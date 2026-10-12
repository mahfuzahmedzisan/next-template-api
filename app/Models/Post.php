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
 * A blog post, shown at /blog/{slug}. The body uses the same Markdown-style
 * text as Project.
 *
 * @property string $title
 * @property string $slug
 * @property string $excerpt
 * @property string $body
 * @property string|null $cover
 * @property string|null $author
 * @property string|null $category
 * @property list<string>|null $tags
 * @property int|null $reading_minutes
 * @property Carbon|null $published_at
 */
#[Fillable(['title', 'slug', 'excerpt', 'body', 'cover', 'author', 'category', 'tags', 'reading_minutes', 'published_at'])]
class Post extends Model implements Seoable, Sitemapable
{
    use HasSeo;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'reading_minutes' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function seoDefaults(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->excerpt,
            'image' => $this->cover,
            'author' => $this->author,
            'og_type' => 'article',
            'schema_type' => 'Article',
            'published_time' => $this->published_at,
            'modified_time' => $this->updated_at,
        ];
    }

    public function seoPath(): string
    {
        return '/blog/'.$this->slug;
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
