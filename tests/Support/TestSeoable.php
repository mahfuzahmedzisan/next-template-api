<?php

namespace Tests\Support;

use App\Contracts\Seo\Seoable;
use App\Models\Concerns\HasSeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Sitemap\Contracts\Sitemapable;

/**
 * Stand-in for a future Blog or Product model in the SEO tests.
 */
class TestSeoable extends Model implements Seoable, Sitemapable
{
    use HasSeo;

    protected $table = 'test_seoables';

    protected $guarded = [];

    protected $casts = ['published' => 'boolean'];

    public static function register(): void
    {
        Schema::create('test_seoables', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        config(['seo.seoables' => ['test_seoable' => self::class]]);
        Relation::morphMap(['test_seoable' => self::class]);
    }

    public function seoDefaults(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->excerpt,
            'author' => 'Jane Writer',
            'og_type' => 'article',
            'schema_type' => 'Article',
            'published_time' => $this->created_at,
            'modified_time' => $this->updated_at,
        ];
    }

    public function seoPath(): string
    {
        return '/blogs/'.$this->slug;
    }

    public function seoIsPublished(): bool
    {
        return (bool) $this->published;
    }

    public function scopeSeoPublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }
}
