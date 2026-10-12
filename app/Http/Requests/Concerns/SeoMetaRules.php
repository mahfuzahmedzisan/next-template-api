<?php

namespace App\Http\Requests\Concerns;

use App\Support\Seo\SeoFields;

/**
 * Validation for the optional `seo` object on a detail record's create/edit
 * request. Merge into rules(): `...$this->seoRules()`.
 */
trait SeoMetaRules
{
    /**
     * @return array<string, mixed>
     */
    protected function seoRules(): array
    {
        return [
            'seo' => ['sometimes', 'nullable', 'array'],
            ...SeoFields::rules('seo'),
        ];
    }
}
