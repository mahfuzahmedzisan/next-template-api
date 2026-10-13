<?php

namespace App\Services\Seo\Audit;

use App\Services\Seo\Audit\Concerns\BuildsCheckResults;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches one public URL the way an HTML-limited crawler does and runs every
 * applicable check against the rendered HTML, as strictly as public SEO
 * checkers grade it.
 */
final class SeoPageScanner
{
    use BuildsCheckResults;

    /** Required properties per schema.org type (Google rich results and schema validators). */
    private const SCHEMA_REQUIRED = [
        'WebSite' => ['name', 'url'],
        'Organization' => ['name', 'url'],
        'Person' => ['name'],
        'WebPage' => ['name', 'url'],
        'AboutPage' => ['name', 'url'],
        'ContactPage' => ['name', 'url'],
        'CollectionPage' => ['name', 'url'],
        'ProfilePage' => ['name', 'url'],
        'ItemPage' => ['name', 'url'],
        'Article' => ['headline', 'image', 'datePublished', 'author'],
        'BlogPosting' => ['headline', 'image', 'datePublished', 'author'],
        'NewsArticle' => ['headline', 'image', 'datePublished', 'author'],
        'TechArticle' => ['headline', 'image', 'datePublished', 'author'],
        'CreativeWork' => ['name'],
        'Service' => ['name'],
        'SoftwareApplication' => ['name'],
        'Product' => ['name'],
        'BreadcrumbList' => ['itemListElement'],
        'ItemList' => ['itemListElement'],
        'FAQPage' => ['mainEntity'],
    ];

    private const SOCIAL_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'your', 'you', 'our', 'are', 'was', 'that', 'this', 'into', 'how',
        'what', 'why', 'who', 'all', 'any', 'can', 'its', 'not', 'one', 'out', 'has', 'have', 'more',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $probes = [];

    public function __construct(private readonly ImageProbe $imageProbe) {}

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
            $results['status_ok'] = $this->result(false, (string) $response->status(), '200', $this->statusMessage($response));
        } else {
            $page = new ScannedPage($url, $response, $html, $ttfb);

            foreach ($keys as $key) {
                $results[$key] = $this->run($key, $page, $target, $robots);
            }
        }

        $checks = [];

        foreach ($results as $key => $result) {
            $meta = SeoCheckCatalog::get($key);
            $counted = $target['indexable']
                && ! ($development && ($meta['environment'] || $meta['category'] === 'performance'));

            $checks[] = SeoCheckCatalog::row($key, $result, $counted);
        }

        return [
            'status_code' => $response->status(),
            'ttfb_ms' => $ttfb,
            'html_bytes' => strlen($html),
            'checks' => $checks,
            'score' => $target['indexable'] ? (SeoCheckCatalog::score($checks) ?? 0) : null,
            'passed' => count(array_filter($checks, static fn (array $check) => $check['passed'])),
            'failed' => count(array_filter($checks, static fn (array $check) => ! $check['passed'])),
            'error' => null,
        ];
    }

    public function client(int $timeout, ?string $userAgent = null): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(min($timeout, 10))
            ->withUserAgent($userAgent ?? (string) config('seo.audit.user_agent'))
            // Guzzle sends no Accept-Encoding unless asked, so servers would answer uncompressed.
            // Brotli is left out: curl builds without it could not decode the body.
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
            ])
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * The real format and size of an image, probed once per audit.
     *
     * @return array<string, mixed>
     */
    public function probe(string $url): array
    {
        return $this->probes[$url] ??= $this->imageProbe->fetch($url);
    }

    /**
     * @param  array{kind: string, page_type: string, url: string, indexable: bool}  $target
     * @return array{passed: bool, actual: ?string, expected: ?string, message: string}
     */
    private function run(string $key, ScannedPage $page, array $target, RobotsRules $robots): array
    {
        return match ($key) {
            'title_present' => $this->titlePresent($page),
            'title_length' => $this->length($page->title(), 'title', 'title'),
            'title_matches_content' => $this->titleMatchesContent($page),
            'description_present' => $this->present($page->meta('description'), 'a short summary', 'Add a meta description or a site default description.'),
            'description_length' => $this->length($page->meta('description'), 'description', 'meta description'),
            'canonical' => $this->canonical($page),
            'robots_meta' => $this->robotsMeta($page, $target['indexable']),
            'head_metadata' => $this->headMetadata($page),
            'og_tags' => $this->ogTags($page),
            'og_image' => $this->ogImage($page),
            'og_image_meta' => $this->ogImageMeta($page),
            'twitter_card' => $this->twitterCard($page),
            'lang' => $this->present($page->lang(), 'e.g. en', 'Set the lang attribute on <html>.'),
            'viewport' => $this->viewport($page),
            'charset' => $this->present($page->charset(), 'utf-8', 'Declare <meta charset="utf-8">.'),
            'structured_data' => $this->structuredData($page),
            'breadcrumbs' => $this->breadcrumbs($page),
            'keywords_meta' => $this->present($page->meta('keywords'), 'a few keywords', 'Search engines ignore it; useful only for internal search.'),
            'author_meta' => $this->present($page->meta('author'), 'an author name', 'Add the author in the SEO fields or the site default.'),
            'h1_single' => $this->h1($page),
            'headings' => $this->headings($page),
            'content_length' => $this->contentLength($page, $target['page_type']),
            'text_ratio' => $this->textRatio($page),
            'internal_links' => $this->internalLinks($page),
            'external_links' => $this->externalLinks($page),
            'image_alt' => $this->imageAlt($page),
            'image_dimensions' => $this->imageDimensions($page),
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

    private function statusMessage(Response $response): string
    {
        if ($response->redirect()) {
            return 'The page redirects to '.($response->header('Location') ?: 'another URL').'. Scan and link to the final URL.';
        }

        return 'The page must answer 200 to be indexed.';
    }

    private function titlePresent(ScannedPage $page): array
    {
        if ($page->title() === null && $page->bodyHasTitle()) {
            return $this->result(false, 'only in <body>', '<title> in <head>', 'The title is streamed into the body. Crawlers that read only <head> miss it.');
        }

        return $this->present($page->title(), 'a unique page title', 'Add a meta title in the SEO settings.');
    }

    private function length(?string $value, string $threshold, string $what): array
    {
        [$min, $max] = SeoCheckCatalog::range($threshold);
        $length = $value === null ? 0 : mb_strlen($value);
        $actual = $length.' characters';
        $expected = "{$min}–{$max} characters";

        if ($length === 0) {
            return $this->result(false, 'missing', $expected, "Add a {$what}.");
        }

        if ($length < $min) {
            return $this->result(false, $actual, $expected, 'The '.$what.' is '.($min - $length).' characters short. Search results have room for more.');
        }

        if ($length > $max) {
            return $this->result(false, $actual, $expected, 'The '.$what.' is '.($length - $max).' characters long and will be cut off in search results.');
        }

        return $this->result(true, $actual, $expected, 'Good length.');
    }

    /**
     * The words that make the title specific (site name and filler removed)
     * must appear in the H1 and mostly in the page text.
     */
    private function titleMatchesContent(ScannedPage $page): array
    {
        $title = $page->title();

        if ($title === null) {
            return $this->result(false, 'no title', 'title words in the H1 and text', 'Add a title first.');
        }

        $siteName = $page->property('og:site_name');
        $core = $siteName !== null && mb_strtolower(trim($title)) !== mb_strtolower($siteName)
            ? str_ireplace($siteName, ' ', $title)
            : $title;
        $words = $this->significantWords($core) ?: $this->significantWords($title);

        if ($words === []) {
            return $this->result(true, $title, 'title words in the H1 and text', 'Nothing specific to compare.');
        }

        $h1 = $this->significantWords((string) $page->h1Text());
        $text = mb_strtolower($page->visibleText());
        $inH1 = array_filter($words, fn (string $word) => $this->matchesAny($word, $h1));
        $inText = array_filter($words, static fn (string $word) => str_contains($text, $word));
        $coverage = (int) round(count($inText) / count($words) * 100);
        $actual = count($inH1).' title '.Str::plural('word', count($inH1))." in the H1, {$coverage}% in the text";

        if ($inH1 === []) {
            return $this->result(false, $actual, 'a title word in the H1, 50%+ in the text', 'The H1 and the title describe different things. Share the main keyword.');
        }

        return $coverage >= 50
            ? $this->result(true, $actual, 'a title word in the H1, 50%+ in the text', 'The title describes the page.')
            : $this->result(false, $actual, 'a title word in the H1, 50%+ in the text', 'Use the title keywords in the page text.');
    }

    /**
     * @return list<string>
     */
    private function significantWords(string $value): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true),
        )));
    }

    /**
     * @param  list<string>  $list
     */
    private function matchesAny(string $word, array $list): bool
    {
        foreach ($list as $candidate) {
            if (str_starts_with($candidate, $word) || str_starts_with($word, $candidate)) {
                return true;
            }
        }

        return false;
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

        if (self::normalizeUrl($links[0]) !== self::normalizeUrl($page->url)) {
            return $this->result(false, $links[0], $page->url, 'The canonical points to another URL, so this page will not be indexed itself.');
        }

        return $this->result(true, $links[0], 'this page’s URL', 'Self-referencing canonical.');
    }

    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return rtrim(trim($url), '/');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower(($parts['scheme'] ?? 'https').'://'.$parts['host']).$port.$path.$query;
    }

    private function robotsMeta(ScannedPage $page, bool $indexable): array
    {
        $directives = strtolower(trim(($page->meta('robots') ?? '').','.$page->header('x-robots-tag'), ', '));
        $noindex = preg_match('/(^|[\s,])(noindex|none)([\s,]|$)/', $directives) === 1;
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

    /**
     * Fetches the page again as a browser. Next.js streams metadata into
     * <body> for clients it does not recognise as bots, and checkers that use
     * a browser user agent then report the title and description as missing.
     */
    private function headMetadata(ScannedPage $page): array
    {
        $agent = (string) config('seo.audit.browser_user_agent');

        try {
            $response = $this->client((int) config('seo.audit.timeout', 15), $agent)->get($page->url);
        } catch (Throwable $exception) {
            return $this->result(false, 'no answer', '<title>, description and canonical in <head>', 'The page did not answer a browser request: '.$this->shortError($exception));
        }

        if ($response->status() !== 200) {
            return $this->result(false, 'HTTP '.$response->status(), '200 with metadata in <head>', 'Browsers get a different answer than crawlers.');
        }

        $browser = new ScannedPage($page->url, $response, $response->body(), $page->ttfbMs);
        $missing = array_keys(array_filter([
            'title' => $browser->title() === null,
            'description' => $browser->meta('description') === null,
            'canonical' => $browser->canonicals() === [],
            'og:title' => $browser->property('og:title') === null,
        ]));

        if ($missing === []) {
            return $this->result(true, 'in <head> for browsers and bots', 'in <head> for every user agent', 'Every checker sees the metadata.');
        }

        return $this->result(
            false,
            'missing in <head> for browsers: '.implode(', ', $missing),
            'in <head> for every user agent',
            $browser->bodyHasTitle()
                ? 'The metadata is streamed into <body> for browsers. Render the page statically so it is in <head> for everyone.'
                : 'Browsers get the page without its metadata.',
        );
    }

    private function ogTags(ScannedPage $page): array
    {
        $required = ['og:title', 'og:description', 'og:url', 'og:type', 'og:site_name', 'og:locale'];
        $missing = array_values(array_filter($required, static fn (string $property) => $page->property($property) === null));
        $expected = implode(', ', $required);

        if ($missing !== []) {
            return $this->result(false, 'missing '.implode(', ', $missing), $expected, 'Fill in every Open Graph tag so shares show the right card.');
        }

        $ogUrl = (string) $page->property('og:url');
        $canonical = $page->canonicals()[0] ?? $page->url;

        if (self::normalizeUrl($ogUrl) !== self::normalizeUrl($canonical)) {
            return $this->result(false, "og:url {$ogUrl}", "og:url = canonical ({$canonical})", 'og:url and the canonical link must be the same URL.');
        }

        return $this->result(true, 'all six, og:url = canonical', $expected, 'Present.');
    }

    private function ogImage(ScannedPage $page): array
    {
        $image = $page->property('og:image');
        $thresholds = (array) config('seo.audit.thresholds.og_image');
        $minWidth = (int) $thresholds['min_width'];
        $minHeight = (int) $thresholds['min_height'];
        $ratio = (float) $thresholds['ratio'];
        $maxBytes = (int) $thresholds['max_bytes'];
        $expected = "JPEG/PNG/WebP, {$minWidth}x{$minHeight}+ at {$ratio}:1, ≤ ".(int) ($maxBytes / 1048576).' MB';

        if ($image === null) {
            return $this->result(false, 'missing', $expected, 'Choose a social image or a site default image.');
        }

        if (preg_match('#^https?://#i', $image) !== 1) {
            return $this->result(false, $image, $expected, 'Social networks need an absolute image URL.');
        }

        $probe = $this->probe($image);

        if (! $probe['ok']) {
            return $this->result(false, (string) ($probe['error'] ?? 'unreadable'), $expected, 'The social image does not load as an image.');
        }

        if ($probe['format'] === 'svg' || ! in_array($probe['content_type'], self::SOCIAL_IMAGE_TYPES, true)) {
            return $this->result(false, ($probe['content_type'] ?: 'no type').", {$probe['format']}", $expected, 'Social networks need a JPEG, PNG, WebP or GIF image.');
        }

        $size = "{$probe['width']}x{$probe['height']}";

        if ($probe['bytes'] !== null && $probe['bytes'] > $maxBytes) {
            return $this->result(false, (int) ceil($probe['bytes'] / 1024).' KB', $expected, 'The image is too heavy for social networks. Compress it.');
        }

        if ($probe['width'] < $minWidth || $probe['height'] < $minHeight) {
            return $this->result(false, $size, $expected, "The image is smaller than {$minWidth}x{$minHeight}; cards will look blurry or small.");
        }

        $actualRatio = $probe['width'] / max(1, $probe['height']);

        if (abs($actualRatio - $ratio) > (float) $thresholds['ratio_tolerance']) {
            return $this->result(false, $size.' ('.number_format($actualRatio, 3).':1)', $expected, "The image is not {$ratio}:1, so networks crop it. Use 1200x628.");
        }

        return $this->result(true, "{$size} {$probe['format']}", $expected, 'Social image is the right size and shape.');
    }

    private function ogImageMeta(ScannedPage $page): array
    {
        $image = $page->property('og:image');
        $expected = 'og:image:width, og:image:height, og:image:alt';

        if ($image === null) {
            return $this->result(false, 'no og:image', $expected, 'Add a social image first.');
        }

        $width = $page->property('og:image:width');
        $height = $page->property('og:image:height');
        $missing = array_keys(array_filter([
            'og:image:width' => $width === null,
            'og:image:height' => $height === null,
            'og:image:alt' => $page->property('og:image:alt') === null,
        ]));

        if ($missing !== []) {
            return $this->result(false, 'missing '.implode(', ', $missing), $expected, 'Declare the image size and alt text so networks render the card before downloading the image.');
        }

        $probe = preg_match('#^https?://#i', $image) === 1 ? $this->probe($image) : ['ok' => false];

        if ($probe['ok'] && ($probe['width'] !== (int) $width || $probe['height'] !== (int) $height)) {
            return $this->result(false, "tags {$width}x{$height}, image {$probe['width']}x{$probe['height']}", 'tags match the image', 'og:image:width and og:image:height do not match the real image.');
        }

        return $this->result(true, "{$width}x{$height} with alt", $expected, 'Present and matching.');
    }

    private function twitterCard(ScannedPage $page): array
    {
        $card = $page->meta('twitter:card');
        $expected = 'twitter:card, twitter:title, twitter:image';

        if (! in_array($card, ['summary', 'summary_large_image', 'app', 'player'], true)) {
            return $this->result(false, $card ?? 'missing', 'summary_large_image', 'Add a twitter:card meta tag.');
        }

        $missing = array_keys(array_filter([
            'twitter:title' => $page->meta('twitter:title') === null,
            'twitter:image' => $page->meta('twitter:image') === null,
        ]));

        return $missing === []
            ? $this->result(true, $card, $expected, 'Present.')
            : $this->result(false, 'missing '.implode(', ', $missing), $expected, 'Add the X card title and image.');
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

        foreach ($blocks as $block) {
            $decoded = json_decode($block, true);

            if (! is_array($decoded)) {
                return $this->result(false, 'invalid JSON', 'valid JSON-LD', 'A JSON-LD block does not parse.');
            }

            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                $context = is_array($item) ? ($item['@context'] ?? null) : null;
                $contexts = is_array($context) ? $context : [$context];

                if (! array_filter($contexts, static fn ($value) => is_string($value) && str_contains($value, 'schema.org'))) {
                    return $this->result(false, 'no schema.org @context', '@context https://schema.org', 'Each JSON-LD block needs "@context": "https://schema.org".');
                }
            }
        }

        $types = [];
        $problems = [];

        foreach ($page->jsonLdItems() ?? [] as $item) {
            $type = $this->schemaType($item);

            if ($type === null) {
                $problems[] = 'an item without @type';

                continue;
            }

            $types[] = $type;
            $missing = $this->missingSchemaProperties($type, $item);

            if ($missing !== []) {
                $problems[] = "{$type} missing ".implode(', ', $missing);
            }
        }

        if ($types === []) {
            return $this->result(false, 'no @type', 'a schema.org type', 'JSON-LD needs an @type.');
        }

        return $problems === []
            ? $this->result(true, implode(', ', array_unique($types)), 'required properties for each type', 'Valid.')
            : $this->result(false, $this->sample($problems, 3), 'required properties for each type', 'Fill in the required structured data properties.');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function schemaType(array $item): ?string
    {
        $types = (array) ($item['@type'] ?? []);

        foreach ($types as $type) {
            if (is_string($type) && isset(self::SCHEMA_REQUIRED[$type])) {
                return $type;
            }
        }

        return is_string($types[0] ?? null) ? $types[0] : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function missingSchemaProperties(string $type, array $item): array
    {
        $filled = static fn ($value) => $value !== null && $value !== '' && $value !== [];
        $missing = array_values(array_filter(
            self::SCHEMA_REQUIRED[$type] ?? [],
            static fn (string $property) => ! $filled($item[$property] ?? null),
        ));

        if ($type === 'Product' && ! $filled($item['offers'] ?? null) && ! $filled($item['review'] ?? null) && ! $filled($item['aggregateRating'] ?? null)) {
            $missing[] = 'offers, review or aggregateRating';
        }

        if (in_array($type, ['BreadcrumbList', 'ItemList'], true) && $missing === []) {
            $elements = array_values((array) $item['itemListElement']);

            foreach ($elements as $index => $element) {
                $isLast = $index === count($elements) - 1;
                $name = $element['name'] ?? ($element['item']['name'] ?? null);

                if (! is_array($element) || ! is_numeric($element['position'] ?? null) || ($type === 'BreadcrumbList' && (! $filled($name) || (! $isLast && ! $filled($element['item'] ?? null))))) {
                    $missing[] = 'complete ListItem (position, name, item)';

                    break;
                }
            }
        }

        if ($type === 'FAQPage' && $missing === []) {
            foreach ((array) $item['mainEntity'] as $question) {
                if (! is_array($question) || ! $filled($question['name'] ?? null) || ! $filled($question['acceptedAnswer']['text'] ?? null)) {
                    $missing[] = 'Question name and acceptedAnswer.text';

                    break;
                }
            }
        }

        return $missing;
    }

    private function breadcrumbs(ScannedPage $page): array
    {
        if ($page->path() === '/') {
            return $this->result(true, 'home page', 'BreadcrumbList on inner pages', 'The home page needs no breadcrumbs.');
        }

        foreach ($page->jsonLdItems() ?? [] as $item) {
            if (in_array('BreadcrumbList', (array) ($item['@type'] ?? []), true)) {
                $names = array_map(
                    static fn ($element) => is_array($element) ? (string) ($element['name'] ?? ($element['item']['name'] ?? '?')) : '?',
                    array_values((array) ($item['itemListElement'] ?? [])),
                );

                return count($names) >= 2
                    ? $this->result(true, implode(' › ', $names), 'BreadcrumbList with 2+ items', 'Breadcrumbs present.')
                    : $this->result(false, count($names).' item', 'BreadcrumbList with 2+ items', 'Breadcrumbs need the home page and this page.');
            }
        }

        return $this->result(false, 'none', 'BreadcrumbList with 2+ items', 'Add breadcrumbs with BreadcrumbList structured data.');
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

    private function headings(ScannedPage $page): array
    {
        $levels = $page->headingLevels();
        $minH2 = (int) config('seo.audit.thresholds.min_h2', 2);
        $minH3 = (int) config('seo.audit.thresholds.min_h3', 1);
        $counts = array_count_values($levels);
        $h2 = $counts[2] ?? 0;
        $h3 = $counts[3] ?? 0;
        $actual = "{$h2} H2, {$h3} H3";
        $expected = "{$minH2}+ H2, {$minH3}+ H3, no skipped levels";

        if ($h2 < $minH2 || $h3 < $minH3) {
            return $this->result(false, $actual, $expected, 'Break the content into sections (H2) with sub-sections (H3).');
        }

        $previous = 1;

        foreach ($levels as $level) {
            if ($level > $previous + 1) {
                return $this->result(false, "H{$previous} followed by H{$level}", $expected, "Heading levels skip from H{$previous} to H{$level}. Use H".($previous + 1).' in between.');
            }

            $previous = $level;
        }

        return $this->result(true, $actual, $expected, 'Clear heading structure.');
    }

    private function contentLength(ScannedPage $page, string $pageType): array
    {
        $min = SeoCheckCatalog::minWords($pageType);
        $words = $page->wordCount();

        return $words >= $min
            ? $this->result(true, "{$words} words", "{$min}+ words", 'Enough content.')
            : $this->result(false, "{$words} words", "{$min}+ words", 'Thin content ranks poorly. Add '.($min - $words).' more words of useful text.');
    }

    private function textRatio(ScannedPage $page): array
    {
        $min = (int) config('seo.audit.thresholds.text_ratio', 10);
        $ratio = $page->textRatio();
        $actual = $page->frameworkDataBytes() > 0
            ? "{$ratio}% ({$page->textRatio(withoutFrameworkData: true)}% without Next.js data)"
            : "{$ratio}%";

        return match (true) {
            $ratio >= $min => $this->result(true, $actual, "{$min}%+", 'Good balance of text and code.'),
            $page->frameworkDataBytes() > 0 => $this->result(false, $actual, "{$min}%+", 'Most of the HTML is code, largely the Next.js RSC payload. Add real text, shorten repeated class lists and keep props sent to client components small.'),
            default => $this->result(false, $actual, "{$min}%+", 'Most of the HTML is code. Add text or trim inline scripts and styles.'),
        };
    }

    private function internalLinks(ScannedPage $page): array
    {
        $min = (int) config('seo.audit.thresholds.min_internal_links', 5);
        $count = count($page->internalLinks());

        return $count >= $min
            ? $this->result(true, "{$count} internal links", "{$min}+", 'Linked into the site.')
            : $this->result(false, "{$count} internal ".Str::plural('link', $count), "{$min}+", 'Link to more related pages on the site.');
    }

    private function externalLinks(ScannedPage $page): array
    {
        $count = count($page->externalLinks());

        return $count > 0
            ? $this->result(true, "{$count} external ".Str::plural('link', $count), '1+', 'Links out to other sites.')
            : $this->result(false, 'none', '1+', 'Checkers like to see links to relevant external sources or profiles.');
    }

    private function imageAlt(ScannedPage $page): array
    {
        $images = $page->images();
        $missing = count(array_filter($images, static fn (array $image) => $image['alt'] === null));

        if ($images === []) {
            return $this->result(true, 'no images', 'alt on every image', 'Nothing to check.');
        }

        return $missing === 0
            ? $this->result(true, count($images).' '.Str::plural('image', count($images)).' with alt', 'alt on every image', 'All images have alt text.')
            : $this->result(false, "{$missing} of ".count($images).' images without alt', 'alt on every image', 'Describe images with alt text (alt="" for decorative ones).');
    }

    private function imageDimensions(ScannedPage $page): array
    {
        $images = array_filter($page->images(), static fn (array $image) => ! $image['fill']);
        $missing = array_values(array_map(
            static fn (array $image) => basename((string) parse_url((string) $image['src'], PHP_URL_PATH)) ?: 'inline image',
            array_filter($images, static fn (array $image) => ! is_numeric($image['width']) || ! is_numeric($image['height'])),
        ));

        if ($images === []) {
            return $this->result(true, 'no sized images', 'width and height on every image', 'Nothing to check.');
        }

        return $missing === []
            ? $this->result(true, count($images).' '.Str::plural('image', count($images)).' sized', 'width and height on every image', 'Images reserve their space.')
            : $this->result(false, $this->sample($missing), 'width and height on every image', 'Set width and height so the layout does not shift while images load.');
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
            : $this->result(false, $this->sample($broken), 'no 4xx/5xx', count($broken).' broken '.Str::plural('link', count($broken)).'.');
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
            : $this->result(false, $this->sample($broken), 'no 4xx/5xx', count($broken).' '.Str::plural('image', count($broken)).' fail to load.');
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
        return $robots->allows($page->path())
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
}
