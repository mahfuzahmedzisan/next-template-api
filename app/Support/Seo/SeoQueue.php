<?php

namespace App\Support\Seo;

final class SeoQueue
{
    public static function connection(): string
    {
        return (string) config('seo.queue.connection', 'redis-seo');
    }

    public static function name(): string
    {
        return (string) config('seo.queue.name', 'seo');
    }
}
