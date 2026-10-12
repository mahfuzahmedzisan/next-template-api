<?php

namespace App\Services\Seo;

use App\Services\ApplicationSettings;
use App\Support\FrontendOrigins;
use App\Support\Seo\SeoFields;
use App\Support\Seo\SeoUrl;

/**
 * The `seo` settings group plus the site identity every resolver, sitemap
 * and JSON-LD block reads. The backend is the only source of the site name
 * and the public site URL.
 */
final class SeoSettings
{
    public const GROUP = 'seo';

    public const DEFAULT_SUFFIX = '- {{site_name}}';

    public const PLACEHOLDERS = ['{{site_name}}', '{{site_short_name}}', '{{page_title}}', '{{year}}'];

    public function __construct(private readonly ApplicationSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $suffix = $this->settings->get(self::GROUP, 'title_suffix');

        return [
            'site_url' => $this->siteUrl(),
            'site_url_setting' => $this->string('site_url'),
            'title_prefix' => $this->string('title_prefix'),
            'title_suffix' => is_string($suffix) ? $suffix : self::DEFAULT_SUFFIX,
            'default_description' => $this->string('default_description'),
            'default_keywords' => SeoFields::keywords($this->settings->get(self::GROUP, 'default_keywords')) ?? [],
            'default_author' => $this->string('default_author'),
            'default_og_image' => $this->string('default_og_image') ?: null,
            'og_locale' => $this->string('og_locale') ?: 'en_US',
            'twitter_site' => $this->string('twitter_site'),
            'twitter_creator' => $this->string('twitter_creator'),
            'robots_disallow' => $this->list('robots_disallow'),
            'robots_extra' => $this->string('robots_extra'),
            'allow_ai_crawlers' => $this->bool('allow_ai_crawlers', true),
            'llms_enabled' => $this->bool('llms_enabled', true),
        ];
    }

    /**
     * Admin view: stored values plus resolved URLs for previews.
     *
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $all = $this->all();

        return [
            ...$all,
            'site_url_fallback' => $this->fallbackSiteUrl(),
            'default_og_image_url' => SeoUrl::image($all['site_url'], $all['default_og_image']),
            'identity' => $this->identity(),
        ];
    }

    /**
     * @return array{name: string, short_name: string, site_url: string, logo_url: ?string, locale: string}
     */
    public function identity(): array
    {
        $general = $this->settings->publicGeneral();
        $siteUrl = $this->siteUrl();

        return [
            'name' => $general['name'],
            'short_name' => $general['short_name'],
            'site_url' => $siteUrl,
            'logo_url' => SeoUrl::image($siteUrl, $general['logo_url']),
            'locale' => $this->string('og_locale') ?: 'en_US',
        ];
    }

    /**
     * Admin setting first, then the first FRONTEND_URLS entry.
     */
    public function siteUrl(): string
    {
        $stored = FrontendOrigins::normalize($this->string('site_url'));

        return $stored ?? $this->fallbackSiteUrl();
    }

    public function fallbackSiteUrl(): string
    {
        $origin = FrontendOrigins::default();

        if ($origin !== '') {
            return $origin;
        }

        return FrontendOrigins::normalize((string) config('app.url')) ?? 'http://localhost:3000';
    }

    /**
     * @param  array<string, mixed>  $values  validated input
     */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            match ($key) {
                'site_url' => $this->putOrForget($key, is_string($value) ? FrontendOrigins::normalize($value) : null),
                'title_prefix', 'title_suffix' => $this->settings->put(self::GROUP, $key, is_string($value) ? trim($value) : ''),
                'default_keywords' => $this->putOrForget($key, SeoFields::keywords($value)),
                'default_og_image' => $this->putOrForget($key, SeoFields::image($value, 'default_og_image')),
                'robots_disallow' => $this->putOrForget($key, $this->paths($value)),
                'allow_ai_crawlers', 'llms_enabled' => $this->settings->put(self::GROUP, $key, (bool) $value),
                default => $this->putOrForget($key, is_string($value) && trim($value) !== '' ? trim($value) : null),
            };
        }
    }

    /**
     * Content-type defaults for detail pages, keyed by seoable alias.
     *
     * @return array<string, array<string, mixed>>
     */
    public function typeTemplates(): array
    {
        $stored = $this->settings->get(self::GROUP, 'type_templates');

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function typeTemplate(string $type): array
    {
        $template = $this->typeTemplates()[$type] ?? [];

        return is_array($template) ? $template : [];
    }

    /**
     * @param  array<string, mixed>  $template
     */
    public function putTypeTemplate(string $type, array $template): void
    {
        $templates = $this->typeTemplates();
        $templates[$type] = array_filter($template, static fn ($value) => $value !== null && $value !== '');
        $this->settings->put(self::GROUP, 'type_templates', $templates);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function files(): array
    {
        $files = $this->settings->get(self::GROUP, 'files');

        return is_array($files) ? $files : [];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordFile(string $file, array $meta): void
    {
        $files = $this->files();
        $files[$file] = $meta;
        $this->settings->put(self::GROUP, 'files', $files);
    }

    public function refresh(): void
    {
        $this->settings->flush();
    }

    private function string(string $key): string
    {
        $value = $this->settings->get(self::GROUP, $key);

        return is_string($value) ? $value : '';
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->settings->get(self::GROUP, $key);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        $value = $this->settings->get(self::GROUP, $key);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return list<string>|null
     */
    private function paths(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }

        if (! is_array($value)) {
            return null;
        }

        $paths = [];

        foreach ($value as $path) {
            $path = is_string($path) ? trim($path) : '';

            if ($path !== '' && str_starts_with($path, '/') && ! in_array($path, $paths, true)) {
                $paths[] = mb_substr($path, 0, 255);
            }
        }

        return $paths === [] ? null : $paths;
    }

    private function putOrForget(string $key, mixed $value): void
    {
        if ($value === null || $value === [] || $value === '') {
            $this->settings->forget(self::GROUP, $key);

            return;
        }

        $this->settings->put(self::GROUP, $key, $value);
    }
}
