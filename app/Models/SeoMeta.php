<?php

namespace App\Models;

use App\Events\SeoInvalidated;
use App\Support\Seo\SeoFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * SEO for one detail record (a blog post, a product), attached through HasSeo.
 *
 * @property list<string>|null $keywords
 */
#[Fillable(SeoFields::FIELDS)]
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $attributes = [
        'robots_index' => true,
        'robots_follow' => true,
        'max_image_preview' => 'large',
        'in_sitemap' => true,
        'title_affix_mode' => 'inherit',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => SeoInvalidated::dispatch());
        static::deleted(fn () => SeoInvalidated::dispatch());
    }

    protected function casts(): array
    {
        return SeoFields::CASTS;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
