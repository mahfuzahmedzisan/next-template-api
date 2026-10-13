<?php

namespace App\Http\Controllers\Internal;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\MediaUploadTokenService;
use App\Services\StoreFinishedUpload;
use App\Support\AllowedMedia;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TusHookController extends Controller
{
    public function __invoke(Request $request, MediaUploadTokenService $tokens, StoreFinishedUpload $uploads, ?string $hook = null): JsonResponse
    {
        $name = strtolower($hook ?: (string) $request->header('Hook-Name', (string) $request->input('Type', '')));

        return match ($name) {
            'pre-create' => $this->preCreate($request, $tokens),
            'pre-finish', 'post-finish' => $this->postFinish($request, $tokens, $uploads),
            'post-terminate' => $this->postTerminate($request),
            default => response()->json(['ok' => true]),
        };
    }

    private function preCreate(Request $request, MediaUploadTokenService $tokens): JsonResponse
    {
        $upload = $this->upload($request);
        $item = $this->authorizedItem($request, $tokens, $upload);

        if ($item instanceof JsonResponse) {
            return $item;
        }

        $filename = (string) ($upload['MetaData']['filename'] ?? '');
        $mime = (string) ($upload['MetaData']['filetype'] ?? '');
        $size = (int) ($upload['Size'] ?? 0);
        $deferred = (bool) ($upload['SizeIsDeferred'] ?? false);

        if ($deferred || $size < 1) {
            return $this->reject(422, 'The upload must include a total length.');
        }

        $reason = AllowedMedia::rejectionReason($filename, $mime, $size);

        if ($reason !== null) {
            return $this->reject(422, $reason);
        }

        if (AllowedMedia::extension($filename) !== $item->extension) {
            return $this->reject(422, 'The uploaded file does not match this library item.');
        }

        if ($item->status !== VideoStatus::AwaitingUpload) {
            return $this->reject(409, 'This file is not waiting for an upload.');
        }

        $tusId = (string) ($upload['ID'] ?? '');

        if ($tusId !== '') {
            $item->forceFill(['tus_id' => $tusId])->save();
        }

        return response()->json(['ok' => true]);
    }

    private function postFinish(Request $request, MediaUploadTokenService $tokens, StoreFinishedUpload $uploads): JsonResponse
    {
        $upload = $this->upload($request);
        $item = $this->itemFromMetadata($upload);

        if ($item === null) {
            return $this->reject(404, 'Media was not found.');
        }

        if ($item->status !== VideoStatus::AwaitingUpload) {
            return response()->json(['ok' => true]);
        }

        $granted = $tokens->find($this->bearer($request));

        if ($granted === null || $granted['media_id'] !== $item->id) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        // tusd fires pre-finish and post-finish, and the adoption job can run too.
        // The shared lock means only one of them imports the file.
        try {
            $reason = Cache::lock('media-finish-'.$item->id, 180)->block(90, function () use ($item, $upload, $uploads): ?string {
                $item->refresh();

                if ($item->status !== VideoStatus::AwaitingUpload) {
                    return null;
                }

                $path = $upload['Storage']['Path'] ?? null;

                if (! is_string($path) || ! is_file($path)) {
                    return 'Finished upload file is missing.';
                }

                $filename = (string) ($upload['MetaData']['filename'] ?? 'file.bin');
                $tusId = (string) ($upload['ID'] ?? $item->tus_id);

                return $uploads->store($item, $path, $filename, $tusId);
            });
        } catch (LockTimeoutException) {
            return $this->reject(409, 'This upload is already being processed.');
        }

        if ($reason !== null) {
            return $this->reject(422, $reason);
        }

        $tokens->forget($this->bearer($request));

        return response()->json(['ok' => true]);
    }

    private function postTerminate(Request $request): JsonResponse
    {
        $upload = $this->upload($request);
        $item = $this->itemFromMetadata($upload);

        if ($item === null) {
            $tusId = (string) ($upload['ID'] ?? '');
            $item = $tusId === '' ? null : MediaItem::query()->where('tus_id', $tusId)->first();
        }

        if ($item !== null && $item->status === VideoStatus::AwaitingUpload) {
            $item->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function authorizedItem(Request $request, MediaUploadTokenService $tokens, array $upload): MediaItem|JsonResponse
    {
        $granted = $tokens->find($this->bearer($request));

        if ($granted === null) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        $item = $this->itemFromMetadata($upload);

        if ($item === null || $granted['media_id'] !== $item->id || $granted['user_id'] !== (int) $item->uploaded_by) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(Request $request): array
    {
        $upload = $request->input('Event.Upload', []);

        return is_array($upload) ? $upload : [];
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function itemFromMetadata(array $upload): ?MediaItem
    {
        $metadata = $upload['MetaData'] ?? [];
        $mediaId = is_array($metadata) ? (int) ($metadata['media_id'] ?? $metadata['video_id'] ?? 0) : 0;

        if ($mediaId < 1) {
            return null;
        }

        return MediaItem::query()->find($mediaId);
    }

    private function bearer(Request $request): string
    {
        $headers = $request->input('Event.HTTPRequest.Header', []);

        if (! is_array($headers)) {
            return '';
        }

        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, 'Authorization') !== 0) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? '') : $values;

            if (is_string($value) && str_starts_with($value, 'Bearer ')) {
                return substr($value, 7);
            }
        }

        return '';
    }

    private function reject(int $status, string $message): JsonResponse
    {
        return response()->json([
            'RejectUpload' => true,
            'HTTPResponse' => [
                'StatusCode' => $status,
                'Body' => $message,
                'Header' => [
                    'Content-Type' => 'text/plain',
                ],
            ],
            'message' => $message,
        ], $status);
    }
}
