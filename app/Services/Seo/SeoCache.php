<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;

/**
 * Versioned keys instead of wildcard deletes, so invalidation works on every
 * cache store. A lost version is re-seeded with the current timestamp, which
 * can never match an older key again.
 */
final class SeoCache
{
    private const VERSION_KEY = 'seo:version';

    public function version(): int
    {
        $version = Cache::get(self::VERSION_KEY);

        if (! is_numeric($version)) {
            Cache::add(self::VERSION_KEY, time());
            $version = Cache::get(self::VERSION_KEY, time());
        }

        return (int) $version;
    }

    public function bump(): void
    {
        $this->version();
        Cache::increment(self::VERSION_KEY);
    }

    public function pageKey(string $path): string
    {
        return 'seo:v'.$this->version().':page:'.$path;
    }

    public function modelKey(string $type, int|string $id): string
    {
        return 'seo:v'.$this->version().':model:'.$type.':'.$id;
    }

    public function ttl(): int
    {
        return (int) config('seo.cache.ttl', 86400);
    }
}
