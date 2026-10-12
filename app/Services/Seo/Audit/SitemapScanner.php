<?php

namespace App\Services\Seo\Audit;

use App\Services\Seo\Audit\Concerns\BuildsCheckResults;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use XMLReader;

/**
 * Validates the sitemap(s) declared in robots.txt (or /sitemap.xml): XML and
 * protocol limits, every URL answering 200 as an indexable self-canonical
 * page, and every indexable page being listed.
 */
final class SitemapScanner
{
    use BuildsCheckResults;

    private const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const IMAGE_NAMESPACE = 'http://www.google.com/schemas/sitemap-image/1.1';

    private const CHANGE_FREQUENCIES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /** W3C Datetime: YYYY, YYYY-MM, YYYY-MM-DD, or with time and timezone. */
    private const W3C_DATE = '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2}))?)?)?$/';

    /**
     * @param  list<string>  $declared  Sitemap lines from robots.txt
     * @param  list<string>  $expectedUrls  indexable pages that must be listed
     * @return array<string, mixed>
     */
    public function scan(string $siteUrl, array $declared, array $expectedUrls, RobotsRules $robots, bool $development): array
    {
        $roots = $declared !== [] ? array_values(array_unique($declared)) : [SeoUrl::join($siteUrl, '/sitemap.xml')];
        $files = $this->crawl($roots);
        $urls = [];
        $images = [];

        foreach ($files as $file) {
            foreach ($file['entries'] as $entry) {
                if ($file['root'] === 'urlset' && $entry['loc'] !== null) {
                    $urls[] = $entry;
                    array_push($images, ...$entry['images']);
                }
            }
        }

        $details = [
            'files' => array_map(static fn (array $file) => array_diff_key($file, ['entries' => true]), $files),
            'urls' => count($urls),
            'sample' => array_slice(array_map(static fn (array $entry) => $entry['loc'], $urls), 0, 50),
            'missing' => [],
        ];

        $results = ['sitemap_available' => $this->available($files)];
        $parsed = array_filter($files, static fn (array $file) => $file['status'] === 200);

        if ($parsed !== []) {
            $results['sitemap_valid_xml'] = $this->validXml($parsed);
            $results['sitemap_limits'] = $this->limits($parsed);
            $results['sitemap_urls_format'] = $this->format($files, $urls, $siteUrl);

            [$ok, $indexable] = $this->fetchUrls($urls, $robots);
            $results['sitemap_urls_ok'] = $ok;
            $results['sitemap_urls_indexable'] = $indexable;
        }

        $listed = array_flip(array_map(static fn (array $entry) => SeoPageScanner::normalizeUrl((string) $entry['loc']), $urls));
        $details['missing'] = array_values(array_filter($expectedUrls, static fn (string $url) => ! isset($listed[SeoPageScanner::normalizeUrl($url)])));
        $results['sitemap_coverage'] = $details['missing'] === []
            ? $this->result(true, count($expectedUrls).' of '.count($expectedUrls).' pages listed', 'every indexable page', 'Complete.')
            : $this->result(false, count($details['missing']).' missing: '.$this->sample($details['missing'], 3), 'every indexable page', 'Indexable pages are missing from the sitemap. Regenerate it, or check the page settings.');

        if ($parsed !== []) {
            $results['sitemap_images'] = $this->images(array_values(array_unique($images)));
        }

        return [...SeoCheckCatalog::section($results, $development), 'details' => $details];
    }

    /**
     * Fetches the sitemaps breadth-first, following sitemap indexes.
     *
     * @param  list<string>  $roots
     * @return list<array<string, mixed>>
     */
    private function crawl(array $roots): array
    {
        $queue = $roots;
        $seen = [];
        $files = [];
        $maxFiles = (int) config('seo.audit.sitemap.max_files', 50);

        while ($queue !== [] && count($files) < $maxFiles) {
            $url = array_shift($queue);

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $file = $this->fetch($url);
            $files[] = $file;

            if ($file['root'] === 'sitemapindex') {
                foreach ($file['entries'] as $entry) {
                    if ($entry['loc'] !== null && preg_match('#^https?://#i', $entry['loc']) === 1) {
                        $queue[] = $entry['loc'];
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @return array{url: string, status: ?int, content_type: ?string, bytes: ?int, gzip: bool, root: ?string, namespace: ?string, count: int, error: ?string, entries: list<array<string, mixed>>}
     */
    private function fetch(string $url): array
    {
        $file = ['url' => $url, 'status' => null, 'content_type' => null, 'bytes' => null, 'gzip' => false, 'root' => null, 'namespace' => null, 'count' => 0, 'error' => null, 'entries' => []];

        try {
            $response = $this->client((int) config('seo.audit.timeout', 15))->get($url);
        } catch (Throwable $exception) {
            return [...$file, 'error' => 'No answer: '.$this->shortError($exception)];
        }

        $file['status'] = $response->status();
        $file['content_type'] = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if ($response->status() !== 200) {
            return [...$file, 'error' => $response->redirect() ? 'Redirects to '.($response->header('Location') ?: 'another URL') : 'HTTP '.$response->status()];
        }

        $body = $response->body();

        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body);
            $file['gzip'] = true;

            if ($decoded === false) {
                return [...$file, 'error' => 'The gzip file does not decompress.'];
            }

            $body = $decoded;
        }

        $file['bytes'] = strlen($body);

        return [...$file, ...$this->parse($body)];
    }

    /**
     * @return array{root: ?string, namespace: ?string, count: int, error: ?string, entries: list<array<string, mixed>>}
     */
    private function parse(string $xml): array
    {
        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $root = null;
        $namespace = null;
        $entries = [];
        $entry = null;
        $error = null;

        try {
            if ($xml === '' || ! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                return ['root' => null, 'namespace' => null, 'count' => 0, 'error' => 'Empty or not XML.', 'entries' => []];
            }

            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($root === null) {
                        $root = $reader->localName;
                        $namespace = $reader->namespaceURI;

                        continue;
                    }

                    if ($reader->depth === 1 && in_array($reader->localName, ['url', 'sitemap'], true)) {
                        $entry = ['loc' => null, 'lastmod' => null, 'changefreq' => null, 'priority' => null, 'images' => []];
                    } elseif ($entry !== null && $reader->depth === 2 && $reader->namespaceURI === self::NAMESPACE && in_array($reader->localName, ['loc', 'lastmod', 'changefreq', 'priority'], true)) {
                        $entry[$reader->localName] = trim($reader->readString());
                    } elseif ($entry !== null && $reader->localName === 'loc' && $reader->namespaceURI === self::IMAGE_NAMESPACE) {
                        $entry['images'][] = trim($reader->readString());
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === 1 && $entry !== null && in_array($reader->localName, ['url', 'sitemap'], true)) {
                    $entries[] = $entry;
                    $entry = null;
                }
            }

            foreach (libxml_get_errors() as $libxmlError) {
                if ($libxmlError->level >= LIBXML_ERR_ERROR) {
                    $error = 'Line '.$libxmlError->line.': '.trim($libxmlError->message);

                    break;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }

        return ['root' => $root, 'namespace' => $namespace, 'count' => count($entries), 'error' => $error, 'entries' => $entries];
    }

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private function available(array $files): array
    {
        $problems = [];

        foreach ($files as $file) {
            if ($file['status'] !== 200) {
                $problems[] = $file['url'].' ('.($file['error'] ?? 'no answer').')';
            } elseif (! preg_match('#^(application|text)/xml$|^application/(x-)?gzip$#', (string) $file['content_type'])) {
                $problems[] = $file['url'].' is '.($file['content_type'] ?: 'untyped');
            }
        }

        if ($files === []) {
            return $this->result(false, 'no sitemap', '200 application/xml', 'No sitemap was found.');
        }

        return $problems === []
            ? $this->result(true, count($files).' '.Str::plural('file', count($files)).' OK', '200 application/xml', 'Reachable.')
            : $this->result(false, $this->sample($problems, 3), '200 application/xml', 'Crawlers cannot read the sitemap. It must answer 200 as XML every time.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function validXml(array $files): array
    {
        $problems = [];

        foreach ($files as $file) {
            if ($file['error'] !== null) {
                $problems[] = $file['url'].': '.$file['error'];
            } elseif (! in_array($file['root'], ['urlset', 'sitemapindex'], true)) {
                $problems[] = $file['url'].': root is <'.($file['root'] ?? '?').'>';
            } elseif ($file['namespace'] !== self::NAMESPACE) {
                $problems[] = $file['url'].': wrong namespace';
            }
        }

        return $problems === []
            ? $this->result(true, 'valid', '<urlset> or <sitemapindex> in the sitemap namespace', 'Valid.')
            : $this->result(false, $this->sample($problems, 2), '<urlset> or <sitemapindex> in the sitemap namespace', 'Fix the sitemap XML.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function limits(array $files): array
    {
        $maxUrls = (int) config('seo.audit.sitemap.max_urls', 50000);
        $maxBytes = (int) config('seo.audit.sitemap.max_bytes', 50 * 1024 * 1024);
        $over = array_values(array_filter($files, static fn (array $file) => $file['count'] > $maxUrls || $file['bytes'] > $maxBytes));
        $largest = max(array_map(static fn (array $file) => $file['count'], $files));

        return $over === []
            ? $this->result(true, "largest file: {$largest} ".Str::plural('URL', $largest), '≤ 50,000 URLs and 50 MB per file', 'Within limits.')
            : $this->result(false, $this->sample(array_map(static fn (array $file) => $file['url'], $over), 2), '≤ 50,000 URLs and 50 MB per file', 'Split the sitemap into a sitemap index.');
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $urls
     */
    private function format(array $files, array $urls, string $siteUrl): array
    {
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $problems = [];
        $seen = [];
        $children = [];

        foreach ($files as $file) {
            if ($file['root'] === 'sitemapindex') {
                array_push($children, ...array_map(static fn (array $entry) => ['loc' => $entry['loc'], 'lastmod' => $entry['lastmod'], 'changefreq' => null, 'priority' => null], $file['entries']));
            }
        }

        foreach ([...$urls, ...$children] as $entry) {
            $loc = (string) $entry['loc'];

            if (preg_match('#^https?://#i', $loc) !== 1) {
                $problems[] = "{$loc} is not absolute";
            } elseif (strtolower((string) parse_url($loc, PHP_URL_HOST)) !== $host || strtolower((string) parse_url($loc, PHP_URL_SCHEME)) !== $scheme) {
                $problems[] = "{$loc} is not on {$scheme}://{$host}";
            } elseif (str_contains($loc, '#')) {
                $problems[] = "{$loc} has a fragment";
            }

            $key = SeoPageScanner::normalizeUrl($loc);

            if (isset($seen[$key])) {
                $problems[] = "{$loc} is listed twice";
            }

            $seen[$key] = true;

            if ($entry['lastmod'] !== null && preg_match(self::W3C_DATE, $entry['lastmod']) !== 1) {
                $problems[] = "{$loc} lastmod \"{$entry['lastmod']}\" is not a W3C date";
            }

            if ($entry['changefreq'] !== null && ! in_array($entry['changefreq'], self::CHANGE_FREQUENCIES, true)) {
                $problems[] = "{$loc} changefreq \"{$entry['changefreq']}\" is not valid";
            }

            if ($entry['priority'] !== null && (! is_numeric($entry['priority']) || $entry['priority'] < 0 || $entry['priority'] > 1)) {
                $problems[] = "{$loc} priority \"{$entry['priority']}\" is not between 0.0 and 1.0";
            }
        }

        return $problems === []
            ? $this->result(true, count($urls).' '.Str::plural('URL', count($urls)).' well formed', 'absolute, same host, unique, valid dates', 'Well formed.')
            : $this->result(false, $this->sample($problems, 3), 'absolute, same host, unique, valid dates', 'Fix the listed sitemap entries.');
    }

    /**
     * Fetches every listed URL (up to the limit) as a crawler would.
     *
     * @param  list<array<string, mixed>>  $urls
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function fetchUrls(array $urls, RobotsRules $robots): array
    {
        $locs = array_slice(array_values(array_unique(array_filter(
            array_map(static fn (array $entry) => (string) $entry['loc'], $urls),
            static fn (string $loc) => preg_match('#^https?://#i', $loc) === 1,
        ))), 0, (int) config('seo.audit.sitemap.url_limit', 200));

        if ($locs === []) {
            return [
                $this->result(false, 'no URLs', 'every URL answers 200', 'The sitemap lists no URLs.'),
                $this->result(false, 'no URLs', 'indexable and self-canonical', 'The sitemap lists no URLs.'),
            ];
        }

        $timeout = (int) config('seo.audit.timeout', 15);
        $responses = Http::pool(
            fn (Pool $pool) => array_map(fn (string $loc) => $this->client($timeout, $pool->as($loc))->get($loc), $locs),
            max(1, (int) config('seo.audit.concurrency', 5)),
        );

        $failed = [];
        $notIndexable = [];

        foreach ($locs as $loc) {
            $response = $responses[$loc] ?? null;

            if (! $response instanceof Response) {
                $failed[] = "{$loc} (no answer)";

                continue;
            }

            if ($response->status() !== 200) {
                $failed[] = $loc.' ('.$response->status().($response->redirect() ? ' → '.$response->header('Location') : '').')';

                continue;
            }

            $page = new ScannedPage($loc, $response, $response->body(), 0);
            $directives = strtolower(($page->meta('robots') ?? '').','.$page->header('x-robots-tag'));
            $canonical = $page->canonicals()[0] ?? null;

            if (preg_match('/(^|[\s,])(noindex|none)([\s,]|$)/', $directives) === 1) {
                $notIndexable[] = "{$loc} is noindex";
            } elseif ($canonical !== null && SeoPageScanner::normalizeUrl($canonical) !== SeoPageScanner::normalizeUrl($loc)) {
                $notIndexable[] = "{$loc} has canonical {$canonical}";
            } elseif (! $robots->allows($page->path())) {
                $notIndexable[] = "{$loc} is blocked by robots.txt";
            }
        }

        $checked = count($locs).' '.Str::plural('URL', count($locs));

        return [
            $failed === []
                ? $this->result(true, "{$checked} OK", 'every URL answers 200', 'All listed URLs work.')
                : $this->result(false, count($failed).' failing: '.$this->sample($failed, 3), 'every URL answers 200', 'List only final URLs that answer 200. Remove redirects and missing pages.'),
            $notIndexable === []
                ? $this->result(true, "{$checked} indexable", 'indexable and self-canonical', 'Every listed URL can be indexed.')
                : $this->result(false, $this->sample($notIndexable, 3), 'indexable and self-canonical', 'List only indexable pages that are their own canonical.'),
        ];
    }

    /**
     * @param  list<string>  $images
     */
    private function images(array $images): array
    {
        $images = array_slice(array_values(array_filter($images, static fn (string $src) => preg_match('#^https?://#i', $src) === 1)), 0, (int) config('seo.audit.sitemap.max_images', 20));

        if ($images === []) {
            return $this->result(true, 'no image entries', 'image entries load', 'Nothing to check.');
        }

        $responses = Http::pool(
            fn (Pool $pool) => array_map(fn (string $src) => $pool->as($src)->timeout((int) config('seo.audit.link_timeout', 5))
                ->withUserAgent((string) config('seo.audit.user_agent'))
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->head($src), $images),
            max(1, (int) config('seo.audit.concurrency', 5)),
        );
        $broken = array_values(array_filter($images, static fn (string $src) => ($responses[$src] ?? null) instanceof Response && $responses[$src]->status() >= 400));

        return $broken === []
            ? $this->result(true, count($images).' '.Str::plural('image', count($images)).' OK', 'image entries load', 'All checked images load.')
            : $this->result(false, $this->sample($broken, 3), 'image entries load', 'Sitemap images fail to load.');
    }

    private function client(int $timeout, ?PendingRequest $request = null): PendingRequest
    {
        return ($request ?? Http::createPendingRequest())
            ->timeout($timeout)
            ->connectTimeout(min($timeout, 10))
            ->withUserAgent((string) config('seo.audit.user_agent'))
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->withOptions(['allow_redirects' => false]);
    }
}
