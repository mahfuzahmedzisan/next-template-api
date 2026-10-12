<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public post card; the detail endpoint adds `body` and the resolved SEO.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
{
    private bool $detail = false;

    public function detailed(): self
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => $this->seoPath(),
            'excerpt' => $this->excerpt,
            'cover_url' => MediaUrl::format($this->cover ?? ''),
            'author' => $this->author,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'reading_minutes' => $this->reading_minutes,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            $this->mergeWhen($this->detail, fn () => [
                'body' => $this->body,
                'seo' => $this->resolvedSeo(),
            ]),
        ];
    }
}
