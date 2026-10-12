<?php

namespace App\Actions\Seo;

use App\Contracts\Seo\Seoable;
use App\Models\SeoMeta;
use App\Support\Seo\SeoFields;
use Illuminate\Database\Eloquent\Model;

/**
 * Saves the validated `seo` part of a detail record's request. Only the keys
 * that were sent change; a missing `seo` object leaves the row alone.
 */
final class SyncSeoMeta
{
    /**
     * @param  array<string, mixed>|null  $input  validated `seo` input
     */
    public function handle(Model&Seoable $model, ?array $input): ?SeoMeta
    {
        if ($input === null) {
            return $model->seo()->first();
        }

        $attributes = SeoFields::clean($input, 'seo.');

        /** @var SeoMeta $meta */
        $meta = $model->seo()->firstOrNew();
        $meta->fill($attributes);
        $meta->save();

        return $meta;
    }
}
