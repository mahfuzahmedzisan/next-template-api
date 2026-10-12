<?php

namespace App\Services\Seo;

use App\Contracts\Seo\Seoable;
use App\Models\SeoPage;
use App\Support\Seo\SeoFields;
use App\Support\Seo\SeoPath;
use App\Support\Seo\SeoUrl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turns stored SEO fields into the final tags for one URL. Static pages fall
 * back to the global SEO settings; detail records fall back to their own
 * content, then to their content-type template, then to the globals.
 */
final class SeoResolver
{
    private const DEFAULT_SCHEMA = [
        'home' => 'WebPage',
        'about' => 'AboutPage',
        'listing' => 'CollectionPage',
        'contact' => 'ContactPage',
    ];

    /** @var array<string, mixed>|null */
    private ?array $settingsMemo = null;

    /** @var array<string, mixed>|null */
    private ?array $identityMemo = null;

    public function __construct(
        private readonly SeoSettings $settings,
        private readonly SeoCache $cache,
    ) {}

    /**
     * Cached only for registered paths, so random paths can't fill the cache.
     *
     * @return array<string, mixed>
     */
    public function forPath(?string $path): array
    {
        $path = SeoPath::normalize($path) ?? '/';
        $key = $this->cache->pageKey($path);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $page = SeoPage::query()->where('path', $path)->first();

        if ($page === null) {
            return $this->build([], [
                'kind' => 'page',
                'path' => $path,
                'label' => $this->labelForPath($path),
                'page_type' => $path === '/' ? 'home' : 'other',
                'registered' => false,
            ]);
        }

        $resolved = $this->forPage($page);
        Cache::put($key, $resolved, $this->cache->ttl());

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function forPage(SeoPage $page): array
    {
        return $this->build($page->only(SeoFields::FIELDS), [
            'kind' => 'page',
            'path' => $page->path,
            'label' => $page->label,
            'page_type' => $page->page_type,
            'registered' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forModel(Model&Seoable $model): array
    {
        $key = $this->cache->modelKey($model->seoType(), $model->getKey());
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $resolved = $this->resolveModel($model);
        Cache::put($key, $resolved, $this->cache->ttl());

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveModel(Model&Seoable $model): array
    {
        $meta = $model->relationLoaded('seo') ? $model->getRelation('seo') : $model->seo()->first();
        $defaults = $model->seoDefaults();
        $type = $model->seoType();

        return $this->build($meta?->only(SeoFields::FIELDS) ?? [], [
            'kind' => 'model',
            'type' => $type,
            'id' => $model->getKey(),
            'path' => SeoPath::normalize($model->seoPath()) ?? '/',
            'label' => (string) ($defaults['title'] ?? Str::headline($type)),
            'page_type' => $type,
            'registered' => true,
            'published' => $model->seoIsPublished(),
            'defaults' => $defaults,
            'template' => $this->settings->typeTemplate($type),
            'lastmod' => $model->getAttribute('updated_at'),
        ]);
    }

    public function forgetMemo(): void
    {
        $this->settingsMemo = null;
        $this->identityMemo = null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function build(array $fields, array $context): array
    {
        $settings = $this->settingsMemo ??= $this->settings->all();
        $identity = $this->identityMemo ??= $this->settings->identity();
        $siteUrl = $identity['site_url'];
        $defaults = (array) ($context['defaults'] ?? []);
        $template = (array) ($context['template'] ?? []);
        $path = (string) $context['path'];

        $vars = [
            '{{site_name}}' => $identity['name'],
            '{{site_short_name}}' => $identity['short_name'],
            '{{year}}' => (string) now()->year,
        ];

        // The home page's label ("Home") is an admin name, not a search title.
        $label = ($context['page_type'] ?? null) === 'home' ? null : ($context['label'] ?? null);
        $rawTitle = $this->render($this->first($fields['meta_title'] ?? null, $defaults['title'] ?? null, $label) ?? $identity['name'], $vars);
        $vars['{{page_title}}'] = $rawTitle;
        $title = $this->title($rawTitle, $fields, $template, $settings, $vars, $identity['name']);

        $description = $this->first(
            $fields['meta_description'] ?? null,
            $defaults['description'] ?? null,
            isset($template['description']) ? $this->render((string) $template['description'], $vars) : null,
            $settings['default_description'],
        );
        $description = $description !== null ? $this->render($description, $vars) : null;

        $canonicalBase = SeoUrl::join($siteUrl, $path);
        $canonical = SeoUrl::absolute($siteUrl, $fields['canonical_url'] ?? null) ?? $canonicalBase;
        $selfCanonical = SeoUrl::same($canonical, $canonicalBase);

        $robotsIndex = (bool) ($fields['robots_index'] ?? true);
        $robotsFollow = (bool) ($fields['robots_follow'] ?? true);

        $image = SeoUrl::image($siteUrl, $fields['og_image'] ?? null)
            ?? SeoUrl::image($siteUrl, $defaults['image'] ?? null)
            ?? SeoUrl::image($siteUrl, $settings['default_og_image'])
            ?? SeoUrl::join($siteUrl, '/og');

        $ogTitle = $this->first($fields['og_title'] ?? null, $title);
        $ogDescription = $this->first($fields['og_description'] ?? null, $description);
        $ogType = $this->first($fields['og_type'] ?? null, $defaults['og_type'] ?? null, $template['og_type'] ?? null, $context['kind'] === 'model' ? 'article' : 'website');
        $schemaType = $this->first($fields['schema_type'] ?? null, $defaults['schema_type'] ?? null, $template['schema_type'] ?? null, self::DEFAULT_SCHEMA[$context['page_type']] ?? null, 'WebPage');

        $published = $this->iso($defaults['published_time'] ?? null);
        $modified = $this->iso($defaults['modified_time'] ?? null);
        $author = $this->first($fields['author'] ?? null, $defaults['author'] ?? null, $settings['default_author']);
        $keywords = $fields['keywords'] ?? null;
        $keywords = is_array($keywords) && $keywords !== [] ? array_values($keywords) : $settings['default_keywords'];

        $priority = $fields['sitemap_priority'] ?? $template['sitemap_priority'] ?? ($path === '/' ? 1.0 : 0.7);
        $changefreq = $this->first($fields['sitemap_changefreq'] ?? null, $template['sitemap_changefreq'] ?? null, 'weekly');
        $isPublished = (bool) ($context['published'] ?? true);

        return [
            'kind' => $context['kind'],
            'type' => $context['type'] ?? null,
            'id' => $context['id'] ?? null,
            'path' => $path,
            'page_type' => $context['page_type'],
            'registered' => (bool) $context['registered'],
            'title' => $title,
            'raw_title' => $rawTitle,
            'description' => $description,
            'keywords' => $keywords,
            'author' => $author,
            'canonical' => $canonical,
            'canonical_base' => $canonicalBase,
            'self_canonical' => $selfCanonical,
            'robots' => [
                'index' => $robotsIndex,
                'follow' => $robotsFollow,
                'max_image_preview' => (string) ($fields['max_image_preview'] ?? 'large'),
            ],
            'open_graph' => [
                'title' => $ogTitle,
                'description' => $ogDescription,
                'image' => $image,
                'image_alt' => $this->first($fields['og_image_alt'] ?? null, $rawTitle),
                'type' => $ogType,
                'locale' => $this->first($fields['og_locale'] ?? null, $settings['og_locale']),
                'site_name' => $identity['name'],
                'url' => $canonical,
            ],
            'twitter' => [
                'card' => $this->first($fields['twitter_card'] ?? null, 'summary_large_image'),
                'title' => $this->first($fields['twitter_title'] ?? null, $ogTitle),
                'description' => $this->first($fields['twitter_description'] ?? null, $ogDescription),
                'image' => SeoUrl::image($siteUrl, $fields['twitter_image'] ?? null) ?? $image,
                'site' => $this->handle($settings['twitter_site']),
                'creator' => $this->handle($settings['twitter_creator']),
            ],
            'published_time' => $published,
            'modified_time' => $modified,
            'schema' => [
                'type' => $schemaType,
                'json_ld' => $this->jsonLd($schemaType, $rawTitle, $description, $canonical, $image, $author, $published, $modified, $identity),
            ],
            'sitemap' => [
                'include' => (bool) ($fields['in_sitemap'] ?? true) && $robotsIndex && $selfCanonical && $isPublished,
                'priority' => round((float) $priority, 1),
                'changefreq' => $changefreq,
                'lastmod' => $context['kind'] === 'model' ? $this->iso($context['lastmod'] ?? null) : null,
            ],
            'identity' => $identity,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $vars
     */
    private function title(string $rawTitle, array $fields, array $template, array $settings, array $vars, string $siteName): string
    {
        $mode = (string) ($fields['title_affix_mode'] ?? 'inherit');

        if ($mode === 'none') {
            return $rawTitle;
        }

        if ($mode === 'inherit' && ! empty($template['title_template'])) {
            return $this->squish($this->render((string) $template['title_template'], $vars));
        }

        [$prefix, $suffix] = $mode === 'custom'
            ? [(string) ($fields['title_prefix'] ?? ''), (string) ($fields['title_suffix'] ?? '')]
            : [(string) $settings['title_prefix'], (string) $settings['title_suffix']];

        $prefix = $this->render($prefix, $vars);
        $suffix = $this->render($suffix, $vars);

        // "Acme - Acme" helps nobody: a page titled with the site name skips an affix that repeats it.
        if ($rawTitle === $siteName) {
            $prefix = str_contains($prefix, $siteName) ? '' : $prefix;
            $suffix = str_contains($suffix, $siteName) ? '' : $suffix;
        }

        return $this->squish($prefix.' '.$rawTitle.' '.$suffix);
    }

    /**
     * @param  array<string, string>  $identity
     * @return array<string, mixed>
     */
    private function jsonLd(string $type, string $name, ?string $description, string $url, string $image, ?string $author, ?string $published, ?string $modified, array $identity): array
    {
        $website = ['@type' => 'WebSite', 'name' => $identity['name'], 'url' => $identity['site_url']];

        if ($type === 'Article') {
            return array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => mb_substr($name, 0, 110),
                'description' => $description,
                'image' => [$image],
                'url' => $url,
                'mainEntityOfPage' => $url,
                'datePublished' => $published,
                'dateModified' => $modified ?? $published,
                'author' => $author !== null ? ['@type' => 'Person', 'name' => $author] : null,
                'publisher' => array_filter([
                    '@type' => 'Organization',
                    'name' => $identity['name'],
                    'logo' => $identity['logo_url'] ? ['@type' => 'ImageObject', 'url' => $identity['logo_url']] : null,
                ]),
            ], static fn ($value) => $value !== null);
        }

        if ($type === 'Product') {
            return array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $name,
                'description' => $description,
                'image' => [$image],
                'url' => $url,
            ], static fn ($value) => $value !== null);
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $name,
            'description' => $description,
            'url' => $url,
            'isPartOf' => $website,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function render(string $value, array $vars): string
    {
        return $this->squish(strtr($value, $vars));
    }

    private function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function first(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function handle(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return str_starts_with($value, '@') ? $value : '@'.$value;
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function labelForPath(string $path): string
    {
        if ($path === '/') {
            return 'Home';
        }

        $segment = (string) Str::of($path)->afterLast('/');

        return Str::headline($segment !== '' ? $segment : $path);
    }
}
