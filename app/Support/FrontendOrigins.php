<?php

namespace App\Support;

class FrontendOrigins
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $urls = config('services.frontend.urls', []);

        if (! is_array($urls)) {
            return [];
        }

        $normalized = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $origin = self::normalize($url);

            if ($origin !== null) {
                $normalized[] = $origin;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function contains(string $origin): bool
    {
        $normalized = self::normalize($origin);

        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, self::all(), true);
    }

    public static function default(): string
    {
        return self::all()[0] ?? '';
    }

    /**
     * Loopback, `.localhost`/`.local` and private or reserved IP literals.
     * Hostnames are not resolved, so this stays cheap enough for every request.
     */
    public static function isLocal(string $origin): bool
    {
        $host = parse_url(trim($origin), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Scheme + host + port only. Paths, queries, and trailing slashes are dropped
     * so `https://app.example.com/admin` and `https://app.example.com` match.
     */
    public static function normalize(string $origin): ?string
    {
        $origin = trim($origin);

        if ($origin === '') {
            return null;
        }

        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
