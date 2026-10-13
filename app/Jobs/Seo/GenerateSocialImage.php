<?php

namespace App\Jobs\Seo;

use App\Services\Seo\SocialImage;
use App\Support\Seo\SeoQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds the 1200x628 share variant off the request path: downloading and
 * resizing a remote image inside a page's SEO lookup would stall the page.
 */
class GenerateSocialImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public string $stored)
    {
        $this->onConnection(SeoQueue::connection());
        $this->onQueue(SeoQueue::name());
    }

    public function uniqueId(): string
    {
        return sha1($this->stored);
    }

    public function handle(SocialImage $images): void
    {
        $images->build($this->stored);
    }
}
