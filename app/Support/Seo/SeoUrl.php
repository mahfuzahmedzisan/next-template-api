<?php

namespace App\Support\Seo;

final class SeoUrl
{
    public static function join(string $siteUrl, string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return rtrim($siteUrl, '/').($path === '/' ? '/' : $path);
    }

    /**
     * Absolute http(s) URLs stay as they are; a path is placed on the site URL.
     */
    public static function absolute(string $siteUrl, ?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return str_starts_with($value, '/') ? self::join($siteUrl, $value) : null;
    }

    /**
     * Public URL of a stored image. Files on the API's public disk are served
     * to browsers and crawlers through the frontend's /api/storage proxy.
     */
    public static function image(string $siteUrl, ?string $stored): ?string
    {
        $stored = trim((string) $stored);

        if ($stored === '' || preg_match('#^(javascript|data|file|vbscript|blob):#i', $stored) === 1) {
            return null;
        }

        if (preg_match('#^https?://#i', $stored) === 1) {
            $path = parse_url($stored, PHP_URL_PATH);
            $apiHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $host = parse_url($stored, PHP_URL_HOST);

            if (is_string($path) && $host === $apiHost && preg_match('#^/storage/(.+)$#', $path, $matches) === 1) {
                return self::join($siteUrl, '/api/storage/'.$matches[1]);
            }

            return $stored;
        }

        $relative = ltrim(str_replace('\\', '/', $stored), '/');

        if (str_starts_with($relative, 'api/storage/')) {
            $relative = substr($relative, strlen('api/storage/'));
        }

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return self::join($siteUrl, '/api/storage/'.$relative);
    }

    /**
     * Same URL ignoring a trailing slash and the scheme/host case.
     */
    public static function same(string $a, string $b): bool
    {
        return self::comparable($a) === self::comparable($b);
    }

    private static function comparable(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return strtolower(($parts['scheme'] ?? 'https').'://'.$parts['host']).$port.$path.$query;
    }
}
