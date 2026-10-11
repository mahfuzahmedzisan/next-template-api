<?php

namespace App\Services;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Jobs\GenerateHlsJob;
use App\Models\MediaItem;
use App\Support\AllowedMedia;
use App\Support\UploadInspector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class StoreFinishedUpload
{
    public function __construct(
        private ImageConversionService $images,
        private SvgSanitizer $svg,
        private UploadInspector $inspector,
    ) {}

    /**
     * Import a tus file that is already the full declared size. The .info
     * offset is not updated by tusd, so the file length is the source of truth.
     */
    public function adoptIfComplete(MediaItem $item): void
    {
        if ($item->status !== VideoStatus::AwaitingUpload) {
            return;
        }

        $lock = Cache::lock('media-finish-'.$item->id, 180);

        if (! $lock->get()) {
            return;
        }

        try {
            $item->refresh();

            if ($item->status !== VideoStatus::AwaitingUpload) {
                return;
            }

            $located = $this->locateFinishedUpload($item);

            if ($located === null) {
                return;
            }

            $this->store($item, $located['path'], $located['filename'], $located['tus_id']);
        } catch (\Throwable $exception) {
            report($exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{path: string, tus_id: string, filename: string}|null
     */
    private function locateFinishedUpload(MediaItem $item): ?array
    {
        $directory = rtrim((string) config('media-hls.upload_dir'), DIRECTORY_SEPARATOR);
        $expected = (int) $item->size;

        if ($expected < 1 || ! is_dir($directory)) {
            return null;
        }

        $tusId = (string) $item->tus_id;

        if (preg_match('/^[A-Za-z0-9]+$/', $tusId) === 1) {
            $direct = $this->finishedUpload($directory, $tusId, $expected, $item);

            if ($direct !== null) {
                return $direct;
            }
        }

        $infos = glob($directory.DIRECTORY_SEPARATOR.'*.info') ?: [];

        foreach ($infos as $infoPath) {
            $payload = json_decode((string) file_get_contents($infoPath), true);

            if (! is_array($payload)) {
                continue;
            }

            $metadata = $payload['MetaData'] ?? null;
            $mediaId = is_array($metadata) ? (int) ($metadata['media_id'] ?? 0) : 0;

            if ($mediaId !== $item->id) {
                continue;
            }

            $id = (string) ($payload['ID'] ?? '');
            $found = $this->finishedUpload($directory, $id, $expected, $item, $payload);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{path: string, tus_id: string, filename: string}|null
     */
    private function finishedUpload(string $directory, string $tusId, int $expected, MediaItem $item, ?array $payload = null): ?array
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $tusId) !== 1) {
            return null;
        }

        $path = $directory.DIRECTORY_SEPARATOR.$tusId;

        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        if ($size === false || $size !== $expected) {
            return null;
        }

        $metadata = is_array($payload) ? ($payload['MetaData'] ?? null) : null;
        $filename = is_array($metadata) ? (string) ($metadata['filename'] ?? '') : '';

        if ($filename === '') {
            $filename = $item->extension !== ''
                ? $item->slug.'.'.$item->extension
                : $item->slug;
        }

        return [
            'path' => $path,
            'tus_id' => $tusId,
            'filename' => $filename,
        ];
    }

    public function store(MediaItem $item, string $path, string $filename, ?string $tusId): ?string
    {
        if ($item->status !== VideoStatus::AwaitingUpload) {
            return null;
        }

        $reason = $this->inspector->rejectionReason($path, $filename);

        if ($reason !== null) {
            $this->fail($item, $path, $reason);

            return $reason;
        }

        $storePath = $path;

        if ($item->kind === MediaKind::Image) {
            try {
                if ($item->extension === 'svg') {
                    $storePath = $this->svg->sanitize($path);
                } else {
                    $storePath = $this->images->encodeOriginalAsWebp($path);
                    $item->extension = 'webp';
                    $item->mime = 'image/webp';
                    $filename = pathinfo($filename, PATHINFO_FILENAME).'.webp';
                }
            } catch (\RuntimeException $exception) {
                $this->fail($item, $path, $exception->getMessage());

                return $exception->getMessage();
            }
        }

        $extension = AllowedMedia::extension($filename);
        $base = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'media';
        $storedName = $extension === '' ? $base : $base.'.'.$extension;

        $item->addMedia($storePath)
            ->usingFileName($storedName)
            ->toMediaCollection('source');

        if ($storePath !== $path) {
            @unlink($path);
        }

        if (is_file($storePath)) {
            @unlink($storePath);
        }

        @unlink($path.'.info');

        $source = $item->getFirstMedia('source');
        $dimensions = $this->imageDimensions($item);

        $item->forceFill([
            'status' => $item->kind->streamsAsHls() ? VideoStatus::Uploaded : VideoStatus::Ready,
            'progress' => $item->kind->streamsAsHls() ? 0 : 100,
            'tus_id' => $tusId ?: $item->tus_id,
            'size' => $source?->size ?: $item->size,
            'mime' => $source?->mime_type ?: $item->mime,
            'width' => $dimensions['width'] ?? $item->width,
            'height' => $dimensions['height'] ?? $item->height,
            'error_message' => null,
        ])->save();

        Audit::log(AuditEvent::MediaUploadCompleted, subject: $item, causer: $item->uploaded_by, properties: [
            'file_name' => $storedName,
            'mime' => $item->mime,
            'size' => $item->size,
        ]);

        if ($item->kind->streamsAsHls()) {
            GenerateHlsJob::dispatch($item->id);
        }

        return null;
    }

    private function fail(MediaItem $item, string $path, string $reason): void
    {
        @unlink($path);
        @unlink($path.'.info');
        $item->forceFill([
            'status' => VideoStatus::Failed,
            'error_message' => $reason,
        ])->save();

        Audit::log(AuditEvent::MediaProcessingFailed, subject: $item, causer: $item->uploaded_by, properties: [
            'stage' => 'upload',
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private function imageDimensions(MediaItem $item): ?array
    {
        if ($item->kind !== MediaKind::Image) {
            return null;
        }

        $media = $item->getFirstMedia('source');
        $path = $media?->getPath();

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);

        if (! is_array($size)) {
            return null;
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }
}
