<?php

namespace App\Services;

use App\Contracts\EncodesHls;
use App\Enums\VideoStatus;
use App\Events\VideoProcessingUpdated;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class HlsTranscodeService
{
    public function __construct(private EncodesHls $encoder) {}

    public function transcode(MediaItem $item): void
    {
        $media = $item->getFirstMedia('source');

        if ($media === null) {
            throw new RuntimeException('Source media is missing.');
        }

        $item->forceFill([
            'status' => VideoStatus::Processing,
            'progress' => 0,
            'error_message' => null,
        ])->save();
        $this->broadcast($item);

        $last = -1;
        $playlist = $item->hlsDirectory().'/master.m3u8';

        $result = $this->encoder->export(
            $media->disk,
            $media->getPathRelativeToRoot(),
            $playlist,
            function (int $percent) use ($item, &$last): void {
                $percent = max(0, min(99, $percent));

                if ($percent < $last + 5) {
                    return;
                }

                $last = $percent;
                $item->forceFill(['progress' => $percent])->save();
                $this->broadcast($item);
            },
            $item->kind->value,
        );

        if (! Storage::disk($media->disk)->exists($playlist)) {
            throw new RuntimeException('HLS playlist was not written.');
        }

        $item->forceFill([
            'status' => VideoStatus::Ready,
            'progress' => 100,
            'hls_path' => $playlist,
            'duration_seconds' => $result->durationSeconds,
            'width' => $result->width,
            'height' => $result->height,
            'error_message' => null,
        ])->save();

        $source = $item->getFirstMedia('source');
        $sourcePath = $source?->getPath();
        $source?->delete();

        if (is_string($sourcePath) && is_file($sourcePath)) {
            @unlink($sourcePath);
        }

        $this->broadcast($item);
    }

    public function broadcast(MediaItem $item): void
    {
        $fresh = $item->fresh() ?? $item;

        if ($fresh->uploaded_by === null) {
            return;
        }

        // Progress pushes are best effort; a Reverb outage must not fail a two-hour transcode.
        try {
            broadcast(new VideoProcessingUpdated(
                userId: (int) $fresh->uploaded_by,
                videoId: $fresh->id,
                status: $fresh->status->value,
                progress: (int) $fresh->progress,
                streamUrl: $fresh->streamUrl(),
                errorMessage: $fresh->error_message,
            ));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
