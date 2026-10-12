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
     * @return list<array{src: ?string, alt: ?string}>
     */
    public function images(): array
    {
        return $this->crawler->filter('body img')->each(function (Crawler $node) {
            $element = $node->getNode(0);
            $src = $element instanceof DOMElement ? trim($element->getAttribute('src')) : '';

            return [
                'src' => $src === '' ? null : $this->absolute($src),
                'alt' => $element instanceof DOMElement && $element->hasAttribute('alt') ? $element->getAttribute('alt') : null,
            ];
        });
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
        $root = $root->count() > 0 ? $root : $this->crawler->filter('body');

        if ($root->count() === 0) {
            return 0;
        }

        $element = $root->getNode(0)?->cloneNode(true);

        if (! $element instanceof DOMElement) {
            return 0;
        }

        foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
            foreach (iterator_to_array($element->getElementsByTagName($tag)) as $child) {
                $child->parentNode?->removeChild($child);
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $element->textContent));

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
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
