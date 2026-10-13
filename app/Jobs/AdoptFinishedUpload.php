<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Services\StoreFinishedUpload;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports a tus upload that finished without its post-finish hook landing.
 * Admin list and detail reads dispatch this instead of doing the import inline.
 */
class AdoptFinishedUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $mediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    public function handle(StoreFinishedUpload $uploads): void
    {
        $item = MediaItem::query()->find($this->mediaId);

        if ($item instanceof MediaItem && $item->status === VideoStatus::AwaitingUpload) {
            $uploads->adoptIfComplete($item);
        }
    }
}
