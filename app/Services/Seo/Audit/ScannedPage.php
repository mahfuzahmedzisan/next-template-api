<?php

namespace App\Services\Seo\Audit;

use DOMElement;
use Illuminate\Http\Client\Response;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Read-only view of a fetched page: head metadata, headings, images, links
 * and visible text. Metadata is read from <head> only, as crawlers do.
 */
final class ScannedPage
{
    private Crawler $crawler;

    private ?Crawler $head = null;

    /** @var list<string>|null */
    private ?array $internalLinks = null;

    public function __construct(
        public readonly string $url,
        private readonly Response $response,
        public readonly string $html,
        public readonly int $ttfbMs,
    ) {
        $this->crawler = new Crawler($html, $url);
    }

    public function header(string $name): string
    {
        return trim($this->response->header($name));
    }

    public function path(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function title(): ?string
    {
        return $this->text($this->head()->filter('title'));
    }

    public function bodyHasTitle(): bool
    {
        return $this->crawler->filter('body title')->count() > 0;
    }

    public function meta(string $name): ?string
    {
        foreach ($this->head()->filter('meta[name]') as $node) {
            if ($node instanceof DOMElement && strcasecmp($node->getAttribute('name'), $name) === 0) {
                return $this->clean($node->getAttribute('content'));
            }
        }

        return null;
    }

    public function property(string $property): ?string
    {
        foreach ($this->head()->filter('meta[property]') as $node) {
            if ($node instanceof DOMElement && strcasecmp($node->getAttribute('property'), $property) === 0) {
                return $this->clean($node->getAttribute('content'));
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function canonicals(): array
    {
        $links = [];

        foreach ($this->head()->filter('link[rel]') as $node) {
            if ($node instanceof DOMElement && strtolower(trim($node->getAttribute('rel'))) === 'canonical') {
                $links[] = trim($node->getAttribute('href'));
            }
        }

        return $links;
    }

    public function lang(): ?string
    {
        $html = $this->crawler->filter('html');

        return $html->count() > 0 ? $this->clean((string) $html->attr('lang')) : null;
    }

    public function charset(): ?string
    {
        $meta = $this->crawler->filter('meta[charset]');

        if ($meta->count() > 0) {
            return $this->clean((string) $meta->attr('charset'));
        }

        return preg_match('/charset=([\w-]+)/i', $this->header('content-type'), $match) === 1 ? $match[1] : null;
    }

    /**
     * @return list<string>
     */
    public function jsonLd(): array
    {
        return array_values(array_filter(
            $this->crawler->filter('script[type="application/ld+json"]')->each(fn (Crawler $node) => trim($node->text('', false))),
            static fn (string $value) => $value !== '',
        ));
    }

    public function count(string $selector): int
    {
        return $this->crawler->filter('body '.$selector)->count();
    }

    /**
     * Heading levels in document order, e.g. [1, 2, 3, 2].
     *
     * @return list<int>
     */
    public function headingLevels(): array
    {
        return $this->crawler->filter('body h1, body h2, body h3, body h4, body h5, body h6')
            ->each(static fn (Crawler $node) => (int) substr($node->nodeName(), 1));
    }

    public function h1Text(): ?string
    {
        return $this->text($this->crawler->filter('body h1'));
    }

    /**
     * `fill` is next/image's absolutely positioned mode: the parent box sets the size.
     *
     * @return list<array{src: ?string, alt: ?string, width: ?string, height: ?string, fill: bool}>
     */
    public function images(): array
    {
        return $this->crawler->filter('body img')->each(function (Crawler $node) {
            $element = $node->getNode(0);
            $attribute = static fn (string $name) => $element instanceof DOMElement && $element->hasAttribute($name) ? $element->getAttribute($name) : null;
            $src = trim((string) $attribute('src'));

            return [
                'src' => $src === '' ? null : $this->absolute($src),
                'alt' => $attribute('alt'),
                'width' => $attribute('width'),
                'height' => $attribute('height'),
                'fill' => $attribute('data-nimg') === 'fill',
            ];
        });
    }

    /**
     * <link> tags in <head> whose rel contains the given token.
     *
     * @return list<array{href: string, sizes: ?string, type: ?string}>
     */
    public function headLinks(string $rel): array
    {
        $links = [];

        foreach ($this->head()->filter('link[rel][href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tokens = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))) ?: [];
            $href = trim($node->getAttribute('href'));

            if ($href !== '' && in_array(strtolower($rel), $tokens, true)) {
                $links[] = [
                    'href' => (string) $this->absolute($href),
                    'sizes' => $this->clean($node->getAttribute('sizes')),
                    'type' => $this->clean($node->getAttribute('type')),
                ];
            }
        }

        return $links;
    }

    /**
     * Unique links to other hosts.
     *
     * @return list<string>
     */
    public function externalLinks(): array
    {
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        $links = [];

        foreach ($this->crawler->filter('body a[href]') as $node) {
            $href = $node instanceof DOMElement ? trim($node->getAttribute('href')) : '';

            if (preg_match('#^(https?:)?//#i', $href) !== 1) {
                continue;
            }

            $absolute = $this->absolute($href);
            $linkHost = strtolower((string) parse_url((string) $absolute, PHP_URL_HOST));

            if ($absolute !== null && $linkHost !== '' && $linkHost !== $host) {
                $links[$absolute] = true;
            }
        }

        return array_keys($links);
    }

    /**
     * Decoded JSON-LD items, with @graph flattened. Null when a block does not parse.
     *
     * @return list<array<string, mixed>>|null
     */
    public function jsonLdItems(): ?array
    {
        $items = [];

        foreach ($this->jsonLd() as $block) {
            $decoded = json_decode($block, true);

            if (! is_array($decoded)) {
                return null;
            }

            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (isset($item['@graph']) && is_array($item['@graph']) ? $item['@graph'] : [$item] as $node) {
                    if (is_array($node)) {
                        $items[] = $node;
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Visible text of the whole <body> (navigation and footer included), as
     * text-to-code ratio tools measure it.
     */
    public function visibleText(): string
    {
        return $this->textOf($this->crawler->filter('body'));
    }

    /**
     * Visible text bytes as a share of the HTML bytes, in percent. Inline
     * framework data (the Next.js RSC payload) is left out unless asked for:
     * it repeats the rendered page for hydration and the author cannot trim it.
     */
    public function textRatio(bool $withFrameworkData = false): float
    {
        $html = strlen($this->html) - ($withFrameworkData ? 0 : $this->frameworkDataBytes());

        return $html <= 0 ? 0.0 : round(strlen($this->visibleText()) / $html * 100, 1);
    }

    /**
     * Bytes of inline Next.js data scripts: the App Router flight chunks and
     * the Pages Router __NEXT_DATA__ blob.
     */
    public function frameworkDataBytes(): int
    {
        preg_match_all('#<script\b(?:[^>]*\bid="__NEXT_DATA__"[^>]*>|[^>]*>\s*self\.__next_f\.push\().*?</script>#is', $this->html, $matches);

        return array_sum(array_map('strlen', $matches[0]));
    }

    /**
     * Unique same-host page links, without fragments, excluding this page.
     *
     * @return list<string>
     */
    public function internalLinks(): array
    {
        if ($this->internalLinks !== null) {
            return $this->internalLinks;
        }

        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        $self = rtrim(strtok($this->url, '#') ?: $this->url, '/');
        $links = [];

        foreach ($this->crawler->filter('body a[href]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel|javascript|data):#i', $href) === 1) {
                continue;
            }

            $absolute = $this->absolute($href);

            if ($absolute === null || strtolower((string) parse_url($absolute, PHP_URL_HOST)) !== $host) {
                continue;
            }

            $absolute = strtok($absolute, '#') ?: $absolute;

            if (rtrim($absolute, '/') !== $self) {
                $links[$absolute] = true;
            }
        }

        return $this->internalLinks = array_keys($links);
    }

    /**
     * Words of visible text in <main> (or <body>), without scripts and styles.
     */
    public function wordCount(): int
    {
        $root = $this->crawler->filter('main');
        $text = $this->textOf($root->count() > 0 ? $root : $this->crawler->filter('body'));

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * @param  Crawler  $nodes  the first node is used
     */
    private function textOf(Crawler $nodes): string
    {
        $element = $nodes->count() > 0 ? $nodes->getNode(0)?->cloneNode(true) : null;

        if (! $element instanceof DOMElement) {
            return '';
        }

        foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
            foreach (iterator_to_array($element->getElementsByTagName($tag)) as $child) {
                $child->parentNode?->removeChild($child);
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private function head(): Crawler
    {
        return $this->head ??= $this->crawler->filter('head');
    }

    private function text(Crawler $nodes): ?string
    {
        return $nodes->count() > 0 ? $this->clean($nodes->first()->text('', true)) : null;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function absolute(string $href): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return (string) parse_url($this->url, PHP_URL_SCHEME).':'.$href;
        }

        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $this->url);

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $base = preg_replace('#/[^/]*$#', '/', parse_url($this->url, PHP_URL_PATH) ?: '/');

        return $origin.$base.$href;
    }
}
