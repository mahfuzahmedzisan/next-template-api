<?php

namespace App\Support\Seo;

use App\Support\MediaUrl;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The SEO field set shared by static pages (seo_pages) and detail records
 * (seo_meta): column names, casts, validation rules and input cleanup.
 */
final class SeoFields
{
    public const FIELDS = [
        'meta_title',
        'meta_description',
        'keywords',
        'author',
        'canonical_url',
        'robots_index',
        'robots_follow',
        'max_image_preview',
        'og_title',
        'og_description',
        'og_image',
        'og_image_alt',
        'og_type',
        'og_locale',
        'twitter_card',
        'twitter_title',
        'twitter_description',
        'twitter_image',
        'schema_type',
        'in_sitemap',
        'sitemap_priority',
        'sitemap_changefreq',
        'title_affix_mode',
        'title_prefix',
        'title_suffix',
    ];

    public const CASTS = [
        'keywords' => 'array',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'in_sitemap' => 'boolean',
        'sitemap_priority' => 'float',
    ];

    public const IMAGE_FIELDS = ['og_image', 'twitter_image'];

    public const OG_TYPES = ['website', 'article', 'product', 'profile'];

    public const TWITTER_CARDS = ['summary', 'summary_large_image'];

    public const IMAGE_PREVIEW = ['none', 'standard', 'large'];

    public const CHANGE_FREQUENCIES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    public const AFFIX_MODES = ['inherit', 'custom', 'none'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : rtrim($prefix, '.').'.';

        return [
            $p.'meta_title' => ['nullable', 'string', 'max:255'],
            $p.'meta_description' => ['nullable', 'string', 'max:500'],
            $p.'keywords' => ['nullable', 'array', 'max:30'],
            $p.'keywords.*' => ['string', 'max:60'],
            $p.'author' => ['nullable', 'string', 'max:120'],
            $p.'canonical_url' => ['nullable', 'string', 'max:2048', self::canonicalRule()],
            $p.'robots_index' => ['sometimes', 'boolean'],
            $p.'robots_follow' => ['sometimes', 'boolean'],
            $p.'max_image_preview' => ['sometimes', 'string', Rule::in(self::IMAGE_PREVIEW)],
            $p.'og_title' => ['nullable', 'string', 'max:255'],
            $p.'og_description' => ['nullable', 'string', 'max:500'],
            $p.'og_image' => ['nullable', 'string', 'max:2048'],
            $p.'og_image_alt' => ['nullable', 'string', 'max:255'],
            $p.'og_type' => ['nullable', 'string', Rule::in(self::OG_TYPES)],
            $p.'og_locale' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            $p.'twitter_card' => ['nullable', 'string', Rule::in(self::TWITTER_CARDS)],
            $p.'twitter_title' => ['nullable', 'string', 'max:255'],
            $p.'twitter_description' => ['nullable', 'string', 'max:500'],
            $p.'twitter_image' => ['nullable', 'string', 'max:2048'],
            $p.'schema_type' => ['nullable', 'string', Rule::in((array) config('seo.schema_types'))],
            $p.'in_sitemap' => ['sometimes', 'boolean'],
            $p.'sitemap_priority' => ['nullable', 'numeric', 'between:0,1'],
            $p.'sitemap_changefreq' => ['nullable', 'string', Rule::in(self::CHANGE_FREQUENCIES)],
            $p.'title_affix_mode' => ['sometimes', 'string', Rule::in(self::AFFIX_MODES)],
            $p.'title_prefix' => ['nullable', 'string', 'max:120'],
            $p.'title_suffix' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Only the keys present in $input are returned, so a partial update never
     * resets fields the client didn't send.
     *
     * @param  array<string, mixed>  $input  already validated
     * @return array<string, mixed>
     */
    public static function clean(array $input, string $errorPrefix = ''): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            $clean[$field] = match ($field) {
                'keywords' => self::keywords($value),
                'robots_index', 'robots_follow', 'in_sitemap' => (bool) $value,
                'max_image_preview' => is_string($value) ? $value : 'large',
                'title_affix_mode' => is_string($value) ? $value : 'inherit',
                'sitemap_priority' => is_numeric($value) ? round((float) $value, 1) : null,
                'og_image', 'twitter_image' => self::image($value, $errorPrefix.$field),
                'title_prefix', 'title_suffix' => is_string($value) && trim($value) !== '' ? trim($value) : null,
                default => is_string($value) && trim($value) !== '' ? trim($value) : null,
            };
        }

        return $clean;
    }

    /**
     * @return list<string>|null
     */
    public static function keywords(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return null;
        }

        $keywords = [];

        foreach ($value as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            $keyword = trim(preg_replace('/\s+/', ' ', $keyword) ?? '');

            if ($keyword !== '' && ! in_array(mb_strtolower($keyword), array_map('mb_strtolower', $keywords), true)) {
                $keywords[] = mb_substr($keyword, 0, 60);
            }
        }

        return $keywords === [] ? null : $keywords;
    }

    public static function image(mixed $value, string $field): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $stored = MediaUrl::store($value);

        if ($stored === null) {
            throw ValidationException::withMessages([$field => 'Enter a valid http or https image URL.']);
        }

        if (! MediaUrl::matchesKinds($stored, ['image'])) {
            throw ValidationException::withMessages([$field => 'Choose an image (jpg, png, webp, gif or svg).']);
        }

        return $stored;
    }

    private static function canonicalRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $value = trim($value);

            if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                return;
            }

            $parts = parse_url($value);

            if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
                $fail('The canonical URL must be an absolute http(s) URL or a path starting with "/".');
            }
        };
    }
}
