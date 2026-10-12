<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public project card; the detail endpoint adds `body` and the resolved SEO.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
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
            'summary' => $this->summary,
            'cover_url' => MediaUrl::format($this->cover ?? ''),
            'tags' => $this->tags ?? [],
            'stack' => $this->stack ?? [],
            'client' => $this->client,
            'year' => $this->year,
            'live_url' => $this->live_url,
            'source_url' => $this->source_url,
            'featured' => $this->featured,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            $this->mergeWhen($this->detail, fn () => [
                'body' => $this->body,
                'seo' => $this->resolvedSeo(),
            ]),
        ];
    }
}
