<?php

namespace App\Models;

use App\Contracts\Seo\Seoable;
use App\Models\Concerns\HasSeo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Sitemap\Contracts\Sitemapable;

/**
 * A portfolio case study, shown at /projects/{slug}. The body is plain
 * Markdown-style text: "## " and "### " headings, "- " list items and
 * blank-line separated paragraphs.
 *
 * @property string $title
 * @property string $slug
 * @property string $summary
 * @property string $body
 * @property string|null $cover
 * @property list<string>|null $tags
 * @property list<string>|null $stack
 * @property string|null $client
 * @property int|null $year
 * @property string|null $live_url
 * @property string|null $source_url
 * @property bool $featured
 * @property Carbon|null $published_at
 * @property int $sort
 */
#[Fillable(['title', 'slug', 'summary', 'body', 'cover', 'tags', 'stack', 'client', 'year', 'live_url', 'source_url', 'featured', 'published_at', 'sort'])]
class Project extends Model implements Seoable, Sitemapable
{
    use HasSeo;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'stack' => 'array',
            'year' => 'integer',
            'featured' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
        ];
    }

    public function seoDefaults(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->summary,
            'image' => $this->cover,
            'og_type' => 'article',
            'schema_type' => 'CreativeWork',
            'published_time' => $this->published_at,
            'modified_time' => $this->updated_at,
        ];
    }

    public function seoPath(): string
    {
        return '/projects/'.$this->slug;
    }

    public function seoIsPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeSeoPublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
