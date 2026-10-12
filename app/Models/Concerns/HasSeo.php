<?php

namespace App\Models\Concerns;

use App\Contracts\Seo\Seoable;
use App\Events\SeoInvalidated;
use App\Models\SeoMeta;
use App\Services\Seo\SeoResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Sitemap\Tags\Url;

/**
 * SEO for a detail-page model. The model implements Seoable (and spatie's
 * Sitemapable), defines seoDefaults() and seoPath(), and is registered in
 * config('seo.seoables') under its morph alias.
 *
 * Override seoIsPublished() and scopeSeoPublished() when the model has drafts.
 *
 * @mixin Model
 */
trait HasSeo
{
    public static function bootHasSeo(): void
    {
        static::saved(function (Model $model): void {
            /** @var Model&Seoable $model */
            SeoInvalidated::dispatch(['seo', $model->seoType().':'.$model->getKey()]);
        });

        static::deleted(function (Model $model): void {
            /** @var Model&Seoable $model */
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                $model->seo()->delete();
            }

            SeoInvalidated::dispatch(['seo', $model->seoType().':'.$model->getKey()]);
        });
    }

    /**
     * @return MorphOne<SeoMeta, $this>
     */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    public function seoType(): string
    {
        return $this->getMorphClass();
    }

    public function seoIsPublished(): bool
    {
        return true;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeSeoPublished(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedSeo(): array
    {
        return app(SeoResolver::class)->forModel($this);
    }

    public function toSitemapTag(): Url|string|array
    {
        $seo = $this->resolvedSeo();

        if (! $seo['sitemap']['include']) {
            return [];
        }

        return Url::create((string) $seo['canonical'])
            ->setPriority((float) $seo['sitemap']['priority'])
            ->setChangeFrequency((string) $seo['sitemap']['changefreq']);
    }
}
