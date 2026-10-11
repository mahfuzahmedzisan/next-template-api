<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Services\HlsTranscodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[FailOnTimeout]
class GenerateHlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public int $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(HlsTranscodeService $service): void
    {
        $item = MediaItem::query()->find($this->mediaId);

        if ($item === null || $item->status === VideoStatus::Ready || ! $item->kind->streamsAsHls()) {
            return;
        }

        $service->transcode($item);
    }

    public function failed(?Throwable $exception): void
    {
        $item = MediaItem::query()->find($this->mediaId);

        if ($item === null || $item->status === VideoStatus::Ready) {
            return;
        }

        $item->forceFill([
            'status' => VideoStatus::Failed,
            'error_message' => $exception?->getMessage() ?: 'Transcode failed.',
        ])->save();

        Audit::log(AuditEvent::MediaProcessingFailed, subject: $item, causer: $item->uploaded_by, properties: [
            'stage' => 'transcode',
            'reason' => mb_substr((string) $item->error_message, 0, 500),
        ]);

        app(HlsTranscodeService::class)->broadcast($item);
    }
}
