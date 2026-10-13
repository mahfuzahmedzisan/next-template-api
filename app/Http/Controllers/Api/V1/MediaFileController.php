<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaFileController extends Controller
{
    /**
     * Current storage path of a library file. Files live in a folder named
     * after their media id, so a URL saved before a rename still finds it.
     */
    public function show(int $media): JsonResponse
    {
        $file = Media::query()
            ->whereKey($media)
            ->where('model_type', (new MediaItem)->getMorphClass())
            ->whereIn('collection_name', ['source', 'poster'])
            ->first();

        if ($file === null) {
            return ApiResponse::notFound();
        }

        return $this->apiSuccess('Media file found.', [
            'path' => $file->id.'/'.$file->file_name,
        ]);
    }
}
