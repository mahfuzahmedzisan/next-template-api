<?php

namespace App\Jobs\Seo;

use App\Services\Seo\SeoFileGenerator;
use App\Support\Seo\SeoQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Debounced rebuild of every SEO file. While one is waiting in the queue,
 * further dispatches are dropped, so a burst of saves costs one rebuild.
 */
class RegenerateSeoFiles implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DEBOUNCE_SECONDS = 30;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onConnection(SeoQueue::connection());
        $this->onQueue(SeoQueue::name());
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'seo-files';
    }

    public function handle(SeoFileGenerator $generator): void
    {
        $generator->generate();
    }
}
