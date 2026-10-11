<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreMediaRequest;
use App\Http\Requests\Api\V1\Admin\StoreMediaThumbnailRequest;
use App\Http\Requests\Api\V1\Admin\UpdateMediaRequest;
use App\Http\Resources\MediaResource;
use App\Jobs\GenerateHlsJob;
use App\Models\MediaItem;
use App\Services\ImageConversionService;
use App\Services\MediaUploadTokenService;
use App\Services\StoreFinishedUpload;
use App\Support\AllowedMedia;
use App\Support\Query\BuildsApiListQuery;
use App\Support\ScoutSearch;
use App\Support\UploadInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = BuildsApiListQuery::make(MediaItem::query()->latest(), [
            AllowedFilter::partial('title'),
            AllowedFilter::exact('status'),
            AllowedFilter::callback('kind', function ($query, mixed $value): void {
                $raw = is_array($value) ? implode(',', $value) : (string) $value;
                $kinds = collect(explode(',', $raw))
                    ->map(fn (string $kind): string => trim($kind))
                    ->filter(fn (string $kind): bool => in_array($kind, ['image', 'video', 'audio', 'pdf', 'document'], true))
                    ->values();

                if ($kinds->isNotEmpty()) {
                    $query->whereIn('kind', $kinds->all());
                }
            }),
            AllowedFilter::callback('q', function ($query, mixed $value): void {
                $term = trim(is_array($value) ? implode(' ', $value) : (string) $value);

                if ($term === '') {
                    return;
                }

                $kind = request()->input('filter.kind');
                $raw = is_array($kind) ? implode(',', $kind) : (string) $kind;
                $kinds = collect(explode(',', $raw))
                    ->map(fn (string $item): string => trim($item))
                    ->filter(fn (string $item): bool => in_array($item, ['image', 'video', 'audio', 'pdf', 'document'], true))
                    ->values();
                $options = [];

                if ($kinds->isNotEmpty()) {
                    $options['filter_by'] = $kinds
                        ->map(fn (string $item): string => 'kind:='.$item)
                        ->implode(' || ');
                }

                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $ids = ScoutSearch::keys(MediaItem::class, $term, $options) ?? [];

                // A missing or stale index must not hide rows the database already matches.
                $query->where(function ($inner) use ($like, $ids): void {
                    $inner->where('search_text', 'like', $like);

                    if ($ids !== []) {
                        $inner->orWhereIn($inner->getModel()->getQualifiedKeyName(), $ids);
                    }
                });
            }),
        ], [
            'title',
            'status',
            'kind',
            'created_at',
        ]);

        $page = $list->paginate(BuildsApiListQuery::perPage($request))->withQueryString();
        $uploads = app(StoreFinishedUpload::class);

        foreach ($page->items() as $item) {
            if ($item instanceof MediaItem && $item->status === VideoStatus::AwaitingUpload) {
                $uploads->adoptIfComplete($item);
                $item->refresh();
            }
        }

        return $this->apiSuccess('Media retrieved successfully.', [
            'media' => MediaResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function slugPreview(Request $request): JsonResponse
    {
        $title = trim($request->string('title')->toString());
        $count = min(10, max(1, $request->integer('count', 1)));

        return $this->apiSuccess('Slugs prepared.', [
            'slugs' => $title === '' ? [] : MediaItem::nextSlugs($title, $count),
        ]);
    }

    public function store(StoreMediaRequest $request, MediaUploadTokenService $tokens): JsonResponse
    {
        $title = $request->string('title')->toString();
        $slug = $request->filled('slug')
            ? $request->string('slug')->toString()
            : MediaItem::uniqueSlug($title);

        if (MediaItem::query()->where('slug', $slug)->exists()) {
            return $this->apiUnprocessable('That slug is already in use.');
        }

        $filename = $request->string('filename')->toString();
        $kind = AllowedMedia::kindFor($filename);

        if (! $kind instanceof MediaKind) {
            return $this->apiUnprocessable('This file type is not allowed.');
        }

        $keywords = MediaItem::normalizeKeywords($request->input('keywords', []) ?? []);

        $item = MediaItem::query()->create([
            'title' => $title,
            'slug' => $slug,
            'kind' => $kind,
            'alt' => $request->input('alt'),
            'keywords' => $keywords,
            'mime' => strtolower($request->string('mime')->toString()),
            'extension' => AllowedMedia::extension($filename),
            'size' => $request->integer('size'),
            'status' => VideoStatus::AwaitingUpload,
            'uploaded_by' => $request->user()->id,
        ]);

        $configured = config('media-hls.endpoint');
        $endpoint = is_string($configured) && $configured !== ''
            ? $configured
            : rtrim((string) config('app.url'), '/').'/tus/';

        if ($request->headers->get('X-Forwarded-Proto') === 'https') {
            $endpoint = (string) preg_replace('#^http://#i', 'https://', $endpoint);
        }

        $endpoint = rtrim($endpoint, '/').'/';

        return $this->apiCreated('Media created.', [
            'media_id' => $item->id,
            'tus_endpoint' => $endpoint,
            'upload_token' => $tokens->issue($item),
            'media' => (new MediaResource($item))->resolve(),
        ]);
    }

    public function show(MediaItem $mediaItem, StoreFinishedUpload $uploads): JsonResponse
    {
        $uploads->adoptIfComplete($mediaItem);
        $mediaItem->refresh();

        return $this->apiSuccess('Media retrieved successfully.', [
            'media' => (new MediaResource($mediaItem))->resolve(),
        ]);
    }

    public function update(UpdateMediaRequest $request, MediaItem $mediaItem): JsonResponse
    {
        $slug = $request->string('slug')->toString();

        if (MediaItem::query()->where('slug', $slug)->whereKeyNot($mediaItem->id)->exists()) {
            return $this->apiUnprocessable('That slug is already in use.');
        }

        $mediaItem->forceFill([
            'title' => $request->string('title')->toString(),
            'slug' => $slug,
            'alt' => $request->input('alt'),
            'keywords' => MediaItem::normalizeKeywords($request->input('keywords', []) ?? []),
        ])->save();

        return $this->apiSuccess('Media updated successfully.', [
            'media' => (new MediaResource($mediaItem))->resolve(),
        ]);
    }

    public function destroy(MediaItem $mediaItem): JsonResponse
    {
        $mediaItem->delete();

        return $this->apiSuccess('Media deleted successfully.');
    }

    public function resumeUpload(MediaItem $mediaItem, MediaUploadTokenService $tokens): JsonResponse
    {
        if ($mediaItem->status !== VideoStatus::AwaitingUpload) {
            return $this->apiUnprocessable('This file is already uploaded.');
        }

        $configured = config('media-hls.endpoint');
        $endpoint = is_string($configured) && $configured !== ''
            ? $configured
            : rtrim((string) config('app.url'), '/').'/tus/';

        if (request()->headers->get('X-Forwarded-Proto') === 'https') {
            $endpoint = (string) preg_replace('#^http://#i', 'https://', $endpoint);
        }

        return $this->apiSuccess('Upload can continue.', [
            'media_id' => $mediaItem->id,
            'tus_endpoint' => rtrim($endpoint, '/').'/',
            'upload_token' => $tokens->issue($mediaItem),
        ]);
    }

    public function retry(MediaItem $mediaItem): JsonResponse
    {
        if (! $mediaItem->kind->streamsAsHls()) {
            return $this->apiUnprocessable('Only audio and video can be converted again.');
        }

        $retryable = [VideoStatus::Failed, VideoStatus::Uploaded, VideoStatus::Processing];

        if (! in_array($mediaItem->status, $retryable, true)) {
            $message = $mediaItem->status === VideoStatus::AwaitingUpload
                ? 'The upload has not finished, so there is nothing to convert.'
                : 'This file is not waiting for another conversion.';

            return $this->apiUnprocessable($message);
        }

        if ($mediaItem->getFirstMedia('source') === null) {
            return $this->apiUnprocessable('The original file is no longer available.');
        }

        $mediaItem->forceFill([
            'status' => VideoStatus::Uploaded,
            'progress' => 0,
            'error_message' => null,
        ])->save();

        GenerateHlsJob::dispatch($mediaItem->id);

        Audit::log(AuditEvent::MediaRetried, subject: $mediaItem);

        return $this->apiSuccess('Conversion started again.', [
            'media' => (new MediaResource($mediaItem))->resolve(),
        ]);
    }

    public function thumbnail(
        StoreMediaThumbnailRequest $request,
        MediaItem $mediaItem,
        UploadInspector $inspector,
        ImageConversionService $images,
    ): JsonResponse {
        if (! $mediaItem->kind->streamsAsHls()) {
            return $this->apiUnprocessable('Only audio and video accept a thumbnail.');
        }

        $file = $request->file('thumbnail');

        if ($file === null) {
            return $this->apiUnprocessable('A thumbnail image is required.');
        }

        $filename = $file->getClientOriginalName();
        $reason = AllowedMedia::rejectionReason($filename, (string) $file->getMimeType(), (int) $file->getSize());

        $extension = AllowedMedia::extension($filename);

        if ($reason !== null || ! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return $this->apiUnprocessable($reason ?? 'Thumbnails must be jpeg, png, webp, or gif.');
        }

        $path = $file->getRealPath();

        if (! is_string($path)) {
            return $this->apiUnprocessable('The thumbnail could not be read.');
        }

        $inspected = $inspector->rejectionReason($path, $filename);

        if ($inspected !== null) {
            return $this->apiUnprocessable($inspected);
        }

        try {
            $clean = $images->encodeOriginalAsWebp($path);
        } catch (\RuntimeException $exception) {
            return $this->apiUnprocessable($exception->getMessage());
        }

        $mediaItem->clearMediaCollection('poster');
        $mediaItem->addMedia($clean)
            ->usingFileName('thumbnail.webp')
            ->toMediaCollection('poster');

        if (is_file($clean)) {
            @unlink($clean);
        }

        Audit::log(AuditEvent::MediaThumbnailUpdated, subject: $mediaItem, properties: [
            'file_name' => $filename,
        ]);

        return $this->apiSuccess('Thumbnail saved.', [
            'media' => (new MediaResource($mediaItem->fresh() ?? $mediaItem))->resolve(),
        ]);
    }
}
