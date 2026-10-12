<?php

namespace App\Listeners\Seo;

use App\Events\SeoInvalidated;
use App\Jobs\Seo\NotifyFrontendRevalidate;
use App\Jobs\Seo\RegenerateSeoFiles;
use App\Services\Seo\SeoCache;

/**
 * Old resolved SEO becomes unreachable at once (version bump); the files and
 * the frontend cache are refreshed by queued jobs after the commit.
 */
class RefreshSeoOutputs
{
    public function __construct(private readonly SeoCache $cache) {}

    public function handle(SeoInvalidated $event): void
    {
        $this->cache->bump();

        RegenerateSeoFiles::dispatch()->delay(now()->addSeconds(RegenerateSeoFiles::DEBOUNCE_SECONDS));
        NotifyFrontendRevalidate::dispatch($event->tags);
    }
}
