<?php

namespace App\Services\Seo\Audit;

use App\Services\Seo\Audit\Concerns\BuildsCheckResults;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Site-wide checks that run once per audit: favicon and icons, index-file
 * redirects, real 404s, trailing slashes, HTTPS and a single host.
 */
final class SiteScanner
{
    use BuildsCheckResults;

    public function __construct(private readonly SeoPageScanner $pages) {}

    /**
     * @param  ?string  $samplePath  an indexable inner page, for the trailing-slash check
     * @return array<string, mixed>
     */
    public function scan(string $siteUrl, ?string $samplePath, bool $development): array
    {
        $home = $this->get(SeoUrl::join($siteUrl, '/'));
        $page = $home instanceof Response && $home->status() === 200
            ? new ScannedPage(SeoUrl::join($siteUrl, '/'), $home, $home->body(), 0)
            : null;

        $results = [
            'favicon_ico' => $this->faviconIco($siteUrl),
            'favicon_links' => $this->faviconLinks($page),
            'apple_touch_icon' => $this->appleTouchIcon($page),
            'web_manifest' => $this->manifest($page),
            'index_redirects' => $this->indexRedirects($siteUrl),
            'soft_404' => $this->soft404($siteUrl),
            'trailing_slash' => $this->trailingSlash($siteUrl, $samplePath),
            'https_redirect' => $this->httpsRedirect($siteUrl),
            'single_host' => $this->singleHost($siteUrl),
        ];

        return [...SeoCheckCatalog::section($results, $development), 'details' => [
            'home_status' => $home instanceof Response ? $home->status() : null,
            'icons' => $page === null ? [] : array_map(static fn (array $link) => $link['href'], [...$page->headLinks('icon'), ...$page->headLinks('apple-touch-icon')]),
        ]];
    }

    private function faviconIco(string $siteUrl): array
    {
        $url = SeoUrl::join($siteUrl, '/favicon.ico');
        $sizes = array_map('intval', (array) config('seo.audit.site.favicon_sizes', [16, 32, 48]));
        $expected = '200 image/x-icon, ICO with '.implode(', ', array_map(static fn (int $size) => "{$size}x{$size}", $sizes));
        $probe = $this->pages->probe($url);

        if ($probe['redirected'] || ($probe['status'] !== null && $probe['status'] >= 300 && $probe['status'] < 400)) {
            return $this->result(false, 'redirects', $expected, '/favicon.ico must answer 200 itself. Checkers do not follow the redirect.');
        }

        if (! in_array($probe['status'], [200, 206], true)) {
            return $this->result(false, $probe['status'] === null ? 'no answer' : 'HTTP '.$probe['status'], $expected, 'Serve a favicon.ico at the site root.');
        }

        if ($probe['format'] !== 'ico') {
            return $this->result(false, ($probe['format'] ?? 'unknown').' file served as '.($probe['content_type'] ?: 'no type'), $expected, '/favicon.ico is not a real ICO file. Checkers report "no favicon".');
        }

        if (! in_array($probe['content_type'], ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            return $this->result(false, 'ICO served as '.($probe['content_type'] ?: 'no type'), $expected, 'Serve favicon.ico with Content-Type image/x-icon.');
        }

        $present = array_map(static fn (array $size) => $size[0], array_filter($probe['sizes'], static fn (array $size) => $size[0] === $size[1]));
        $missing = array_values(array_diff($sizes, $present));
        $actual = 'ICO '.implode(', ', array_map(static fn (int $size) => "{$size}x{$size}", array_unique($present)));

        return $missing === []
            ? $this->result(true, $actual, $expected, 'A real multi-size ICO.')
            : $this->result(false, $actual, $expected, 'Add the '.implode(', ', array_map(static fn (int $size) => "{$size}x{$size}", $missing)).' sizes to favicon.ico.');
    }

    private function faviconLinks(?ScannedPage $page): array
    {
        if ($page === null) {
            return $this->result(false, 'home page did not load', '<link rel="icon"> that loads', 'The home page must answer 200.');
        }

        $links = $page->headLinks('icon');

        if ($links === []) {
            return $this->result(false, 'none', '<link rel="icon"> that loads', 'Declare the icon with <link rel="icon">.');
        }

        $broken = [];

        foreach ($links as $link) {
            $probe = $this->pages->probe($link['href']);

            if (! $probe['ok']) {
                $broken[] = $link['href'].' ('.($probe['error'] ?? 'unreadable').')';
            } elseif ($link['type'] === 'image/x-icon' && $probe['format'] !== 'ico') {
                $broken[] = $link['href'].' claims image/x-icon but is '.$probe['format'];
            }
        }

        return $broken === []
            ? $this->result(true, count($links).' icon '.Str::plural('link', count($links)).' OK', '<link rel="icon"> that loads', 'Icons load.')
            : $this->result(false, $this->sample($broken, 2), '<link rel="icon"> that loads', 'Fix the icon links in <head>.');
    }

    private function appleTouchIcon(?ScannedPage $page): array
    {
        $size = (int) config('seo.audit.site.apple_touch_size', 180);
        $expected = "{$size}x{$size} PNG";
        $link = $page?->headLinks('apple-touch-icon')[0] ?? null;

        if ($link === null) {
            return $this->result(false, 'none', $expected, 'Add <link rel="apple-touch-icon"> for iOS home screens.');
        }

        $probe = $this->pages->probe($link['href']);

        if (! $probe['ok']) {
            return $this->result(false, (string) ($probe['error'] ?? 'unreadable'), $expected, 'The apple-touch-icon does not load.');
        }

        return $probe['format'] === 'png' && $probe['width'] === $size && $probe['height'] === $size
            ? $this->result(true, "{$probe['width']}x{$probe['height']} png", $expected, 'Present.')
            : $this->result(false, "{$probe['width']}x{$probe['height']} {$probe['format']}", $expected, "Use a {$size}x{$size} PNG.");
    }

    private function manifest(?ScannedPage $page): array
    {
        $sizes = array_map('intval', (array) config('seo.audit.site.manifest_icon_sizes', [192, 512]));
        $expected = 'name and '.implode(' + ', array_map(static fn (int $size) => "{$size}px", $sizes)).' icons';
        $link = $page?->headLinks('manifest')[0] ?? null;

        if ($link === null) {
            return $this->result(false, 'none', $expected, 'Add a web app manifest.');
        }

        $response = $this->get($link['href']);
        $json = $response instanceof Response && $response->status() === 200 ? $response->json() : null;

        if (! is_array($json)) {
            return $this->result(false, $response instanceof Response ? 'HTTP '.$response->status() : 'no answer', $expected, 'The manifest does not load as JSON.');
        }

        $declared = [];

        foreach ((array) ($json['icons'] ?? []) as $icon) {
            foreach (preg_split('/\s+/', (string) ($icon['sizes'] ?? '')) ?: [] as $entry) {
                if (preg_match('/^(\d+)x(\d+)$/i', $entry, $match) === 1 && $match[1] === $match[2]) {
                    $declared[] = (int) $match[1];
                }
            }
        }

        $missing = array_values(array_diff($sizes, $declared));

        if (($json['name'] ?? $json['short_name'] ?? '') === '') {
            return $this->result(false, 'no name', $expected, 'Give the manifest a name.');
        }

        return $missing === []
            ? $this->result(true, (string) ($json['name'] ?? $json['short_name']), $expected, 'Present.')
            : $this->result(false, 'missing '.implode(', ', array_map(static fn (int $size) => "{$size}px", $missing)).' icon', $expected, 'Add the missing icon sizes to the manifest.');
    }

    private function indexRedirects(string $siteUrl): array
    {
        $home = SeoPageScanner::normalizeUrl(SeoUrl::join($siteUrl, '/'));
        $problems = [];

        foreach ((array) config('seo.audit.site.index_paths', []) as $path) {
            $response = $this->get(SeoUrl::join($siteUrl, $path));

            if (! $response instanceof Response) {
                $problems[] = "{$path} (no answer)";

                continue;
            }

            $location = $this->location($response, $siteUrl);

            if (! in_array($response->status(), [301, 308], true) || $location === null || SeoPageScanner::normalizeUrl($location) !== $home) {
                $problems[] = "{$path} ({$response->status()}".($location !== null ? " → {$location}" : '').')';
            }
        }

        return $problems === []
            ? $this->result(true, 'all redirect to /', '301 to /', 'Index URLs point to the home page.')
            : $this->result(false, $this->sample($problems), '301 to /', 'Redirect index files permanently to the home page so they do not compete with it.');
    }

    private function soft404(string $siteUrl): array
    {
        $path = '/seo-health-missing-'.Str::lower(Str::random(10));
        $response = $this->get(SeoUrl::join($siteUrl, $path));

        if (! $response instanceof Response) {
            return $this->result(false, 'no answer', '404', 'The site did not answer a missing URL.');
        }

        return in_array($response->status(), [404, 410], true)
            ? $this->result(true, (string) $response->status(), '404', 'Missing pages answer 404.')
            : $this->result(false, (string) $response->status(), '404', 'A missing URL answers '.$response->status().'. Search engines index it as a duplicate (soft 404).');
    }

    private function trailingSlash(string $siteUrl, ?string $samplePath): array
    {
        if ($samplePath === null || $samplePath === '/') {
            return $this->result(true, 'no inner pages', 'one version per URL', 'Nothing to check.');
        }

        $path = rtrim($samplePath, '/');
        $response = $this->get(SeoUrl::join($siteUrl, $path.'/'));

        if (! $response instanceof Response) {
            return $this->result(false, 'no answer', "{$path}/ → {$path}", 'The site did not answer.');
        }

        $location = $this->location($response, $siteUrl);
        $target = SeoPageScanner::normalizeUrl(SeoUrl::join($siteUrl, $path));

        return in_array($response->status(), [301, 308], true) && $location !== null && SeoPageScanner::normalizeUrl($location) === $target && ! str_ends_with((string) parse_url($location, PHP_URL_PATH), '/')
            ? $this->result(true, "{$path}/ → {$path} ({$response->status()})", "{$path}/ → {$path}", 'One version per URL.')
            : $this->result(false, "{$path}/ answers {$response->status()}".($location !== null ? " → {$location}" : ''), "{$path}/ → {$path}", 'Redirect URLs with a trailing slash to the version without it.');
    }

    private function httpsRedirect(string $siteUrl): array
    {
        if (! str_starts_with(strtolower($siteUrl), 'https://')) {
            return $this->result(false, 'site URL is http', 'http → https', 'Serve the site over HTTPS.');
        }

        $http = 'http://'.substr($siteUrl, strlen('https://'));
        $response = $this->get(SeoUrl::join($http, '/'));

        if (! $response instanceof Response) {
            return $this->result(true, 'http does not answer', 'http → https', 'Plain HTTP is closed.');
        }

        $location = (string) $response->header('Location');

        return in_array($response->status(), [301, 308], true) && str_starts_with(strtolower($location), 'https://')
            ? $this->result(true, "{$response->status()} → {$location}", 'http → https', 'HTTP redirects to HTTPS.')
            : $this->result(false, (string) $response->status(), 'http → https', 'Redirect every HTTP request to HTTPS permanently.');
    }

    private function singleHost(string $siteUrl): array
    {
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.') || str_ends_with($host, '.localhost')) {
            return $this->result(true, 'not applicable', 'www and non-www agree', 'Local or IP host.');
        }

        $other = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        $scheme = (string) parse_url($siteUrl, PHP_URL_SCHEME);
        $response = $this->get("{$scheme}://{$other}/");

        if (! $response instanceof Response) {
            return $this->result(true, "{$other} does not resolve", 'www and non-www agree', 'Only one host answers.');
        }

        $location = strtolower((string) $response->header('Location'));

        return in_array($response->status(), [301, 308], true) && str_contains($location, '://'.$host)
            ? $this->result(true, "{$other} → {$host}", 'www and non-www agree', 'One host.')
            : $this->result(false, "{$other} answers {$response->status()}", 'www and non-www agree', "Redirect {$other} permanently to {$host}.");
    }

    private function location(Response $response, string $siteUrl): ?string
    {
        $location = trim($response->header('Location'));

        if ($location === '') {
            return null;
        }

        return str_starts_with($location, '/') ? SeoUrl::join($siteUrl, $location) : $location;
    }

    private function get(string $url): ?Response
    {
        try {
            return $this->client()->get($url);
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): PendingRequest
    {
        return $this->pages->client((int) config('seo.audit.link_timeout', 5) * 2);
    }
}
