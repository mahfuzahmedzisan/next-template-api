<?php

namespace App\Services\Seo\Audit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Fetches one public URL the way an HTML-limited crawler does and runs every
 * applicable check against the rendered HTML.
 */
final class SeoPageScanner
{
    /**
     * @param  array{kind: string, page_type: string, url: string, indexable: bool}  $target
     * @return array{status_code: ?int, ttfb_ms: ?int, html_bytes: ?int, checks: list<array<string, mixed>>, score: ?int, passed: int, failed: int, error: ?string}
     */
    public function scan(array $target, RobotsRules $robots, bool $development): array
    {
        $url = $target['url'];
        $started = microtime(true);

        try {
            $response = $this->client((int) config('seo.audit.timeout', 15))->get($url);
        } catch (ConnectionException $exception) {
            return $this->failure('The page did not answer: '.$this->shortError($exception));
        } catch (Throwable $exception) {
            return $this->failure('The page could not be fetched: '.$this->shortError($exception));
        }

        $ttfb = $this->ttfb($response, $started);
        $html = $response->body();
        $keys = SeoCheckCatalog::forPage($target['kind'], $target['page_type'], $target['indexable']);
        $results = [];

        if ($response->status() !== 200) {
            $results['status_ok'] = $this->result(false, (string) $response->status(), '200', 'The page must answer 200 to be indexed.');
        } else {
            $page = new ScannedPage($url, $response, $html, $ttfb);

            foreach ($keys as $key) {
                $results[$key] = $this->run($key, $page, $robots, $target['indexable']);
            }
        }

        $checks = [];

        foreach ($results as $key => $result) {
            $meta = SeoCheckCatalog::get($key);
            $counted = $target['indexable']
                && ! ($development && ($meta['environment'] || $meta['category'] === 'performance'));

            $checks[] = [
                'key' => $key,
                'label' => $meta['label'],
                'category' => $meta['category'],
                'weight' => $meta['weight'],
                'priority' => $meta['priority'],
                'fix_minutes' => $meta['fix_minutes'],
                'informational' => $meta['informational'],
                'environment' => $meta['environment'],
                'counted' => $counted,
                ...$result,
            ];
        }

        return [
            'status_code' => $response->status(),
            'ttfb_ms' => $ttfb,
            'html_bytes' => strlen($html),
            'checks' => $checks,
            'score' => $target['indexable'] ? self::score($checks) : null,
            'passed' => count(array_filter($checks, static fn (array $check) => $check['passed'])),
            'failed' => count(array_filter($checks, static fn (array $check) => ! $check['passed'])),
            'error' => null,
        ];
    }

    /**
     * Passed weight ÷ total weight of the counted checks.
     *
     * @param  list<array<string, mixed>>  $checks
     */
    public static function score(array $checks): int
    {
        $total = 0;
        $passed = 0;

        foreach ($checks as $check) {
            if (! $check['counted']) {
                continue;
            }

            $total += (int) $check['weight'];
            $passed += $check['passed'] ? (int) $check['weight'] : 0;
        }

        return $total === 0 ? 0 : (int) round($passed / $total * 100);
    }

    public function client(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(min($timeout, 10))
            ->withUserAgent((string) config('seo.audit.user_agent'))
            // Guzzle sends no Accept-Encoding unless asked, so servers would answer uncompressed.
            // Brotli is left out: curl builds without it could not decode the body.
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
            ])
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @return array{passed: bool, actual: ?string, expected: ?string, message: string}
     */
    private function run(string $key, ScannedPage $page, RobotsRules $robots, bool $indexable): array
    {
        return match ($key) {
            'title_present' => $this->titlePresent($page),
            'title_length' => $this->length($page->title(), 30, 60, 'title'),
            'description_present' => $this->descriptionPresent($page),
            'description_length' => $this->length($page->meta('description'), 70, 160, 'meta description'),
            'canonical' => $this->canonical($page),
            'robots_meta' => $this->robotsMeta($page, $indexable),
            'og_tags' => $this->ogTags($page),
            'og_image' => $this->ogImage($page),
            'twitter_card' => $this->present($page->meta('twitter:card'), 'summary_large_image', 'Add a twitter:card meta tag.'),
            'lang' => $this->present($page->lang(), 'e.g. en', 'Set the lang attribute on <html>.'),
            'viewport' => $this->viewport($page),
            'charset' => $this->present($page->charset(), 'utf-8', 'Declare <meta charset="utf-8">.'),
            'structured_data' => $this->structuredData($page),
            'keywords_meta' => $this->present($page->meta('keywords'), 'a few keywords', 'Search engines ignore it; useful only for internal search.'),
            'author_meta' => $this->present($page->meta('author'), 'an author name', 'Add the author in the SEO fields or the site default.'),
            'h1_single' => $this->h1($page),
            'h2_present' => $this->h2($page),
            'image_alt' => $this->imageAlt($page),
            'content_length' => $this->contentLength($page),
            'internal_links' => $this->internalLinks($page),
            'broken_links' => $this->brokenLinks($page),
            'broken_images' => $this->brokenImages($page),
            'status_ok' => $this->result(true, '200', '200', 'The page answers 200 OK.'),
            'robots_txt_allows' => $this->robotsTxt($page, $robots),
            'https' => $this->https($page),
            'hsts' => $this->hsts($page),
            'compression' => $this->compression($page),
            'ttfb' => $this->ttfbCheck($page),
            'html_size' => $this->htmlSize($page),
        };
    }

    /**
     * @return array{passed: bool, actual: ?string, expected: ?string, message: string}
     */
    private function result(bool $passed, ?string $actual, ?string $expected, string $message): array
    {
        return [
            'passed' => $passed,
            'actual' => $actual === null ? null : mb_substr($actual, 0, 300),
            'expected' => $expected,
            'message' => $message,
        ];
    }

    private function present(?string $value, string $expected, string $hint): array
    {
        return $value !== null && $value !== ''
            ? $this->result(true, $value, $expected, 'Present.')
            : $this->result(false, null, $expected, $hint);
    }

    private function titlePresent(ScannedPage $page): array
    {
        if ($page->title() === null && $page->bodyHasTitle()) {
            return $this->result(false, 'only in <body>', '<title> in <head>', 'The title is streamed into the body. Crawlers that read only <head> miss it.');
        }

        return $this->present($page->title(), 'a unique page title', 'Add a meta title in the SEO settings.');
    }

    private function descriptionPresent(ScannedPage $page): array
    {
        return $this->present($page->meta('description'), 'a short summary', 'Add a meta description or a site default description.');
    }

    private function length(?string $value, int $min, int $max, string $what): array
    {
        $length = $value === null ? 0 : mb_strlen($value);
        $actual = $length.' characters';
        $expected = "{$min}–{$max} characters";

        if ($length === 0) {
            return $this->result(false, 'missing', $expected, "Add a {$what}.");
        }

        if ($length < $min) {
            return $this->result(false, $actual, $expected, "The {$what} is short. Search results have room for more.");
        }

        if ($length > $max) {
            return $this->result(false, $actual, $expected, "The {$what} is long and will be cut off in search results.");
        }

        return $this->result(true, $actual, $expected, 'Good length.');
    }

    private function canonical(ScannedPage $page): array
    {
        $links = $page->canonicals();

        if ($links === []) {
            return $this->result(false, 'missing', 'one absolute URL', 'Add a canonical link.');
        }

        if (count($links) > 1) {
            return $this->result(false, count($links).' canonical links', 'one', 'Keep a single canonical link.');
        }

        if (preg_match('#^https?://#i', $links[0]) !== 1) {
            return $this->result(false, $links[0], 'an absolute http(s) URL', 'Use an absolute canonical URL.');
        }

        return $this->result(true, $links[0], 'an absolute URL', 'Canonical set.');
    }

    private function robotsMeta(ScannedPage $page, bool $indexable): array
    {
        $directives = strtolower(trim(($page->meta('robots') ?? '').','.$page->header('x-robots-tag'), ', '));
        $noindex = str_contains($directives, 'noindex');
        $actual = $directives === '' ? 'index, follow (default)' : $directives;

        if (! $indexable) {
            return $noindex
                ? $this->result(true, $actual, 'noindex', 'Kept out of search results as configured.')
                : $this->result(false, $actual, 'noindex', 'This page is set to noindex but the page does not say so.');
        }

        return $noindex
            ? $this->result(false, $actual, 'index', 'The page tells search engines not to index it.')
            : $this->result(true, $actual, 'index', 'Indexable.');
    }

    private function ogTags(ScannedPage $page): array
    {
        $missing = array_values(array_filter(
            ['og:title', 'og:description', 'og:url'],
            static fn (string $property) => in_array($page->property($property), [null, ''], true),
        ));

        return $missing === []
            ? $this->result(true, 'og:title, og:description, og:url', 'all three', 'Present.')
            : $this->result(false, 'missing '.implode(', ', $missing), 'og:title, og:description, og:url', 'Fill in the social title and description.');
    }

    private function ogImage(ScannedPage $page): array
    {
        $image = $page->property('og:image');

        if ($image === null || $image === '') {
            return $this->result(false, 'missing', 'an absolute image URL', 'Choose a social image or a site default image.');
        }

        if (preg_match('#^https?://#i', $image) !== 1) {
            return $this->result(false, $image, 'an absolute image URL', 'Social networks need an absolute image URL.');
        }

        return $this->result(true, $image, 'an absolute image URL', 'Present.');
    }

    private function viewport(ScannedPage $page): array
    {
        $viewport = $page->meta('viewport');

        return $viewport !== null && str_contains(strtolower($viewport), 'width=device-width')
            ? $this->result(true, $viewport, 'width=device-width', 'Present.')
            : $this->result(false, $viewport ?? 'missing', 'width=device-width', 'Add a responsive viewport meta tag.');
    }

    private function structuredData(ScannedPage $page): array
    {
        $blocks = $page->jsonLd();

        if ($blocks === []) {
            return $this->result(false, 'none', 'at least one JSON-LD block', 'Add structured data so search engines understand the page.');
        }

        $types = [];

        foreach ($blocks as $block) {
            $decoded = json_decode($block, true);

            if (! is_array($decoded)) {
                return $this->result(false, 'invalid JSON', 'valid JSON-LD', 'A JSON-LD block does not parse.');
            }

            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                if (is_array($item) && isset($item['@type'])) {
                    $types[] = is_array($item['@type']) ? implode('/', $item['@type']) : (string) $item['@type'];
                }
            }
        }

        return $types === []
            ? $this->result(false, 'no @type', 'a schema.org @type', 'JSON-LD needs an @type.')
            : $this->result(true, implode(', ', array_unique($types)), 'a schema.org type', 'Valid.');
    }

    private function h1(ScannedPage $page): array
    {
        $count = $page->count('h1');

        return match (true) {
            $count === 1 => $this->result(true, '1 H1', 'exactly 1', 'One main heading.'),
            $count === 0 => $this->result(false, 'no H1', 'exactly 1', 'Add one main heading.'),
            default => $this->result(false, "{$count} H1 headings", 'exactly 1', 'Keep a single H1 and use H2 for sections.'),
        };
    }

    private function h2(ScannedPage $page): array
    {
        $count = $page->count('h2');

        return $count > 0
            ? $this->result(true, "{$count} H2", 'at least 1', 'Sections have headings.')
            : $this->result(false, 'no H2', 'at least 1', 'Break the content into sections with H2 headings.');
    }

    private function imageAlt(ScannedPage $page): array
    {
        $images = $page->images();
        $missing = count(array_filter($images, static fn (array $image) => $image['alt'] === null));

        if ($images === []) {
            return $this->result(true, 'no images', 'alt on every image', 'Nothing to check.');
        }

        return $missing === 0
            ? $this->result(true, count($images).' images with alt', 'alt on every image', 'All images have alt text.')
            : $this->result(false, "{$missing} of ".count($images).' images without alt', 'alt on every image', 'Describe images with alt text (alt="" for decorative ones).');
    }

    private function contentLength(ScannedPage $page): array
    {
        $min = (int) config('seo.audit.detail_min_words', 300);
        $words = $page->wordCount();

        return $words >= $min
            ? $this->result(true, "{$words} words", "{$min}+ words", 'Enough content.')
            : $this->result(false, "{$words} words", "{$min}+ words", 'Thin content ranks poorly. Add more useful text.');
    }

    private function internalLinks(ScannedPage $page): array
    {
        $count = count($page->internalLinks());

        return $count > 0
            ? $this->result(true, "{$count} internal links", 'at least 1', 'Linked into the site.')
            : $this->result(false, 'none', 'at least 1', 'Link to related pages on the site.');
    }

    private function brokenLinks(ScannedPage $page): array
    {
        $links = array_slice($page->internalLinks(), 0, (int) config('seo.audit.max_links', 25));

        if ($links === []) {
            return $this->result(true, 'no links', 'no 4xx/5xx', 'Nothing to check.');
        }

        $broken = $this->brokenUrls($links);

        return $broken === []
            ? $this->result(true, count($links).' '.Str::plural('link', count($links)).' OK', 'no 4xx/5xx', 'All checked links work.')
            : $this->result(false, implode(', ', array_slice($broken, 0, 5)), 'no 4xx/5xx', count($broken).' broken link(s).');
    }

    private function brokenImages(ScannedPage $page): array
    {
        $sources = array_values(array_unique(array_filter([
            ...array_map(static fn (array $image) => $image['src'], $page->images()),
            $page->property('og:image'),
        ], static fn (?string $src) => $src !== null && preg_match('#^https?://#i', $src) === 1)));
        $sources = array_slice($sources, 0, (int) config('seo.audit.max_images', 20));

        if ($sources === []) {
            return $this->result(true, 'no images', 'no 4xx/5xx', 'Nothing to check.');
        }

        $broken = $this->brokenUrls($sources);

        return $broken === []
            ? $this->result(true, count($sources).' '.Str::plural('image', count($sources)).' OK', 'no 4xx/5xx', 'All checked images load.')
            : $this->result(false, implode(', ', array_slice($broken, 0, 5)), 'no 4xx/5xx', count($broken).' image(s) fail to load.');
    }

    /**
     * HEAD each URL (GET when HEAD isn't allowed). Only real 4xx/5xx answers
     * count as broken; a slow or unreachable host is not the page's fault.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function brokenUrls(array $urls): array
    {
        $timeout = (int) config('seo.audit.link_timeout', 5);
        $concurrency = max(1, (int) config('seo.audit.concurrency', 5));
        $responses = Http::pool(
            fn (Pool $pool) => array_map(
                fn (string $url) => $pool->as($url)->timeout($timeout)
                    ->withUserAgent((string) config('seo.audit.user_agent'))
                    ->withOptions(['allow_redirects' => ['max' => 5]])
                    ->head($url),
                $urls,
            ),
            $concurrency,
        );

        $broken = [];

        foreach ($urls as $url) {
            $response = $responses[$url] ?? null;

            if (! $response instanceof Response) {
                continue;
            }

            if (in_array($response->status(), [405, 501], true)) {
                try {
                    $response = $this->client($timeout)->withOptions(['allow_redirects' => ['max' => 5]])->get($url);
                } catch (Throwable) {
                    continue;
                }
            }

            if ($response->status() >= 400) {
                $broken[] = $url.' ('.$response->status().')';
            }
        }

        return $broken;
    }

    private function robotsTxt(ScannedPage $page, RobotsRules $robots): array
    {
        $path = $page->path();

        return $robots->allows($path)
            ? $this->result(true, 'allowed', 'allowed', 'Crawlers may visit this page.')
            : $this->result(false, 'blocked', 'allowed', 'robots.txt blocks this page. Remove it from the disallowed paths.');
    }

    private function https(ScannedPage $page): array
    {
        return str_starts_with(strtolower($page->url), 'https://')
            ? $this->result(true, 'https', 'https', 'Secure.')
            : $this->result(false, 'http', 'https', 'Serve the site over HTTPS.');
    }

    private function hsts(ScannedPage $page): array
    {
        $header = $page->header('strict-transport-security');

        return $header !== ''
            ? $this->result(true, $header, 'Strict-Transport-Security', 'Present.')
            : $this->result(false, 'missing', 'Strict-Transport-Security', 'Send an HSTS header from the web server.');
    }

    private function compression(ScannedPage $page): array
    {
        $encoding = strtolower($page->header('x-encoded-content-encoding') ?: $page->header('content-encoding'));

        return preg_match('/\b(gzip|br|deflate|zstd)\b/', $encoding) === 1
            ? $this->result(true, $encoding, 'gzip or br', 'Compressed.')
            : $this->result(false, $encoding === '' ? 'none' : $encoding, 'gzip or br', 'Enable gzip or Brotli on the web server.');
    }

    private function ttfbCheck(ScannedPage $page): array
    {
        $limit = (int) config('seo.audit.ttfb_ms', 800);

        return $page->ttfbMs <= $limit
            ? $this->result(true, "{$page->ttfbMs} ms", "≤ {$limit} ms", 'Fast.')
            : $this->result(false, "{$page->ttfbMs} ms", "≤ {$limit} ms", 'The server is slow to respond. Cache the page or speed up its data.');
    }

    private function htmlSize(ScannedPage $page): array
    {
        $limit = (int) config('seo.audit.max_html_kb', 512);
        $kb = (int) ceil(strlen($page->html) / 1024);

        return $kb <= $limit
            ? $this->result(true, "{$kb} KB", "≤ {$limit} KB", 'Small enough.')
            : $this->result(false, "{$kb} KB", "≤ {$limit} KB", 'The HTML is heavy. Move data out of the page or paginate.');
    }

    private function ttfb(Response $response, float $started): int
    {
        $stats = $response->handlerStats();
        $seconds = $stats['starttransfer_time'] ?? null;

        return (int) round(is_numeric($seconds) && $seconds > 0 ? $seconds * 1000 : (microtime(true) - $started) * 1000);
    }

    /**
     * @return array{status_code: ?int, ttfb_ms: ?int, html_bytes: ?int, checks: list<array<string, mixed>>, score: ?int, passed: int, failed: int, error: ?string}
     */
    private function failure(string $message): array
    {
        return [
            'status_code' => null,
            'ttfb_ms' => null,
            'html_bytes' => null,
            'checks' => [],
            'score' => null,
            'passed' => 0,
            'failed' => 0,
            'error' => mb_substr($message, 0, 500),
        ];
    }

    private function shortError(Throwable $exception): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '', 0, 200);
    }
}
