<?php

namespace App\Contracts\Seo;

use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A detail-page model (blog post, product) that carries its own SEO.
 * Implemented through the HasSeo trait plus seoDefaults() and seoPath().
 */
interface Seoable
{
    /**
     * @return MorphOne<SeoMeta, Model>
     */
    public function seo(): MorphOne;

    /**
     * Values taken from the record itself when its SEO fields are empty.
     *
     * @return array{title?: ?string, description?: ?string, image?: ?string, author?: ?string, og_type?: ?string, schema_type?: ?string, published_time?: mixed, modified_time?: mixed}
     */
    public function seoDefaults(): array;

    /**
     * Public path of the detail page, e.g. "/blogs/my-post".
     */
    public function seoPath(): string;

    /**
     * Morph alias, e.g. "blog".
     */
    public function seoType(): string;

    public function seoIsPublished(): bool;

    /**
     * Records that can appear in the sitemap and the audit.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeSeoPublished(Builder $query): Builder;
}
