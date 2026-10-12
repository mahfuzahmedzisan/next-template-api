<?php

namespace App\Services\Seo\Audit;

/**
 * Every check the SEO health scan runs, with its weight in the section score.
 *
 * - section: page (every scanned URL), site (once per audit), robots, sitemap.
 * - informational: shown on its own, weight 1, Google doesn't rank on it.
 * - environment: depends on the server (TLS, HSTS, compression, hosts); on a
 *   development scan it is stored but left out of the score, like performance.
 *
 * Labels may use :title and :description, replaced by the strict ranges.
 */
final class SeoCheckCatalog
{
    public const CATEGORIES = [
        'meta' => 'Meta',
        'content' => 'Content',
        'configuration' => 'Configuration',
        'performance' => 'Performance',
        'site' => 'Site',
        'robots' => 'robots.txt',
        'sitemap' => 'Sitemap',
    ];

    public const SECTIONS = [
        'pages' => 'Pages',
        'site' => 'Site',
        'sitemap' => 'Sitemap',
        'robots' => 'robots.txt',
    ];

    /** Share of the overall score; sections that were not scanned are left out. */
    public const SECTION_WEIGHTS = ['pages' => 70, 'site' => 10, 'sitemap' => 10, 'robots' => 10];

    public const PRIORITY_RANK = ['high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * @var array<string, array{label: string, category: string, weight: int, priority: string, fix_minutes: int, section?: string, informational?: bool, environment?: bool, audit_level?: bool}>
     */
    public const CHECKS = [
        'title_present' => ['label' => 'Page has a title', 'category' => 'meta', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'title_length' => ['label' => 'Title is :title characters', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'title_unique' => ['label' => 'Title is unique on the site', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5, 'audit_level' => true],
        'title_matches_content' => ['label' => 'Title matches the page content', 'category' => 'meta', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 10],
        'description_present' => ['label' => 'Page has a meta description', 'category' => 'meta', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'description_length' => ['label' => 'Meta description is :description characters', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'description_unique' => ['label' => 'Meta description is unique on the site', 'category' => 'meta', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 5, 'audit_level' => true],
        'canonical' => ['label' => 'One absolute, self-referencing canonical link', 'category' => 'meta', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 5],
        'robots_meta' => ['label' => 'Search engines may index the page', 'category' => 'meta', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'head_metadata' => ['label' => 'Metadata is in <head> for every crawler', 'category' => 'meta', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 30],
        'og_tags' => ['label' => 'Complete Open Graph tags', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'og_image' => ['label' => 'Social image loads at 1.91:1, 1200x628 or larger', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'og_image_meta' => ['label' => 'Social image size and alt tags', 'category' => 'meta', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 5],
        'twitter_card' => ['label' => 'X (Twitter) card with title and image', 'category' => 'meta', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 5],
        'lang' => ['label' => 'Page language is set', 'category' => 'meta', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 5],
        'viewport' => ['label' => 'Mobile viewport', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'charset' => ['label' => 'Character set is declared', 'category' => 'meta', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5],
        'structured_data' => ['label' => 'Valid structured data with required properties', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 15],
        'breadcrumbs' => ['label' => 'Breadcrumb structured data', 'category' => 'meta', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 15],
        'keywords_meta' => ['label' => 'Meta keywords', 'category' => 'meta', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5, 'informational' => true],
        'author_meta' => ['label' => 'Meta author', 'category' => 'meta', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5, 'informational' => true],

        'h1_single' => ['label' => 'Exactly one H1 heading', 'category' => 'content', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 10],
        'headings' => ['label' => 'H2 and H3 subheadings without skipped levels', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 15],
        'content_length' => ['label' => 'Enough text content', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 30],
        'text_ratio' => ['label' => 'Text-to-code ratio', 'category' => 'content', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 30, 'informational' => true],
        'internal_links' => ['label' => 'Links to other pages on the site', 'category' => 'content', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 10],
        'external_links' => ['label' => 'Links to other websites', 'category' => 'content', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5, 'informational' => true],
        'image_alt' => ['label' => 'Images have alt text', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'image_dimensions' => ['label' => 'Images declare width and height', 'category' => 'content', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 10],
        'broken_links' => ['label' => 'No broken internal links', 'category' => 'content', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 15],
        'broken_images' => ['label' => 'No broken images', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],

        'status_ok' => ['label' => 'Page answers 200 OK', 'category' => 'configuration', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 15],
        'robots_txt_allows' => ['label' => 'robots.txt allows the page', 'category' => 'configuration', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'https' => ['label' => 'Served over HTTPS', 'category' => 'configuration', 'weight' => 3, 'priority' => 'high', 'fix_minutes' => 30, 'environment' => true],
        'hsts' => ['label' => 'HSTS header', 'category' => 'configuration', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 10, 'environment' => true],
        'compression' => ['label' => 'Compressed response', 'category' => 'configuration', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 10, 'environment' => true],

        'ttfb' => ['label' => 'Fast server response', 'category' => 'performance', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 60],
        'html_size' => ['label' => 'Small HTML document', 'category' => 'performance', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 30],

        'favicon_ico' => ['label' => '/favicon.ico is a real ICO file', 'category' => 'site', 'section' => 'site', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 15],
        'favicon_links' => ['label' => 'Icon links resolve to images', 'category' => 'site', 'section' => 'site', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'apple_touch_icon' => ['label' => 'Apple touch icon (180x180)', 'category' => 'site', 'section' => 'site', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 10],
        'web_manifest' => ['label' => 'Web app manifest', 'category' => 'site', 'section' => 'site', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 10],
        'index_redirects' => ['label' => 'Index files redirect to the home page', 'category' => 'site', 'section' => 'site', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'soft_404' => ['label' => 'Missing pages answer 404', 'category' => 'site', 'section' => 'site', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 15],
        'trailing_slash' => ['label' => 'Trailing-slash URLs redirect to one version', 'category' => 'site', 'section' => 'site', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 10],
        'https_redirect' => ['label' => 'HTTP redirects to HTTPS', 'category' => 'site', 'section' => 'site', 'weight' => 3, 'priority' => 'high', 'fix_minutes' => 15, 'environment' => true],
        'single_host' => ['label' => 'One host (www and non-www agree)', 'category' => 'site', 'section' => 'site', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 15, 'environment' => true],

        'robots_available' => ['label' => 'robots.txt answers 200 as plain text', 'category' => 'robots', 'section' => 'robots', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 10],
        'robots_size' => ['label' => 'robots.txt is under 500 KB', 'category' => 'robots', 'section' => 'robots', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 10],
        'robots_syntax' => ['label' => 'robots.txt has valid syntax', 'category' => 'robots', 'section' => 'robots', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'robots_wildcard_group' => ['label' => 'Rules for every crawler (User-agent: *)', 'category' => 'robots', 'section' => 'robots', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 5],
        'robots_not_blocking_site' => ['label' => 'robots.txt does not block the whole site', 'category' => 'robots', 'section' => 'robots', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'robots_assets_allowed' => ['label' => 'CSS, JS and images are crawlable', 'category' => 'robots', 'section' => 'robots', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'robots_pages_allowed' => ['label' => 'No indexable page is blocked', 'category' => 'robots', 'section' => 'robots', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'robots_sitemap_declared' => ['label' => 'Sitemap declared and reachable', 'category' => 'robots', 'section' => 'robots', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'robots_ai_policy' => ['label' => 'AI crawler policy', 'category' => 'robots', 'section' => 'robots', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5, 'informational' => true],

        'sitemap_available' => ['label' => 'Sitemap answers 200 as XML', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 10],
        'sitemap_valid_xml' => ['label' => 'Sitemap is valid XML with the sitemap namespace', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 10],
        'sitemap_limits' => ['label' => 'Within 50,000 URLs and 50 MB per file', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 15],
        'sitemap_urls_format' => ['label' => 'URLs are absolute, on this host, unique, with valid dates', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'sitemap_urls_ok' => ['label' => 'Every URL answers 200 without redirects', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 15],
        'sitemap_urls_indexable' => ['label' => 'Every URL is indexable and self-canonical', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 15],
        'sitemap_coverage' => ['label' => 'Every indexable page is listed', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 10],
        'sitemap_images' => ['label' => 'Sitemap images load', 'category' => 'sitemap', 'section' => 'sitemap', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 10],
    ];

    /** Checks run on a noindex page when it is included: configuration only, never scored. */
    public const NOINDEX_CHECKS = ['status_ok', 'title_present', 'robots_meta'];

    /**
     * @return array{label: string, category: string, weight: int, priority: string, fix_minutes: int, section: string, informational: bool, environment: bool, audit_level: bool}
     */
    public static function get(string $key): array
    {
        $check = self::CHECKS[$key];

        return [
            ...$check,
            'label' => self::label($check['label']),
            'section' => $check['section'] ?? 'page',
            'informational' => $check['informational'] ?? false,
            'environment' => $check['environment'] ?? false,
            'audit_level' => $check['audit_level'] ?? false,
        ];
    }

    /**
     * A stored check: catalog metadata plus the result.
     *
     * @param  array{passed: bool, actual: ?string, expected: ?string, message: string}  $result
     * @return array<string, mixed>
     */
    public static function row(string $key, array $result, bool $counted): array
    {
        $meta = self::get($key);

        return [
            'key' => $key,
            'label' => $meta['label'],
            'category' => $meta['category'],
            'weight' => $meta['weight'],
            'priority' => $meta['priority'],
            'fix_minutes' => $meta['fix_minutes'],
            'informational' => $meta['informational'],
            'environment' => $meta['environment'],
            'counted' => $counted,
            ...$result,
        ];
    }

    /**
     * Rows, score and counts for a site-level section. Environment checks are
     * stored but not counted on a development scan.
     *
     * @param  array<string, array{passed: bool, actual: ?string, expected: ?string, message: string}>  $results
     * @return array{score: ?int, checks: list<array<string, mixed>>, passed: int, failed: int}
     */
    public static function section(array $results, bool $development): array
    {
        $checks = [];

        foreach ($results as $key => $result) {
            $checks[] = self::row($key, $result, ! ($development && self::get($key)['environment']));
        }

        return [
            'score' => self::score($checks),
            'checks' => $checks,
            'passed' => count(array_filter($checks, static fn (array $check) => $check['passed'])),
            'failed' => count(array_filter($checks, static fn (array $check) => ! $check['passed'])),
        ];
    }

    /**
     * Passed weight ÷ total weight of the counted checks; null when none count.
     *
     * @param  list<array<string, mixed>>  $checks
     */
    public static function score(array $checks): ?int
    {
        $total = 0;
        $passed = 0;

        foreach ($checks as $check) {
            if (! $check['counted']) {
                continue;
            }

            $total += (int) $check['weight'];
            $passed += $check['passed'] ? (int) $check['weight'] : 0;
        }

        return $total === 0 ? null : (int) round($passed / $total * 100);
    }

    /**
     * @return list<string>
     */
    public static function forSection(string $section): array
    {
        return array_keys(array_filter(self::CHECKS, static fn (array $check) => ($check['section'] ?? 'page') === $section));
    }

    /**
     * The per-page checks that apply to a page. Uniqueness checks are added
     * when the audit finishes, once every page is known.
     *
     * @return list<string>
     */
    public static function forPage(string $kind, string $pageType, bool $indexable): array
    {
        if (! $indexable) {
            return self::NOINDEX_CHECKS;
        }

        $skip = $kind === 'page' ? (array) (config('seo.audit.skip')[$pageType] ?? []) : [];

        return array_values(array_filter(
            self::forSection('page'),
            static fn (string $key) => ! in_array($key, $skip, true) && ! (self::CHECKS[$key]['audit_level'] ?? false),
        ));
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function range(string $name): array
    {
        $range = (array) config("seo.audit.thresholds.{$name}", [0, 0]);

        return [(int) ($range[0] ?? 0), (int) ($range[1] ?? 0)];
    }

    public static function minWords(string $pageType): int
    {
        $table = (array) config('seo.audit.thresholds.min_words', []);

        return (int) ($table[$pageType] ?? $table['default'] ?? 300);
    }

    private static function label(string $label): string
    {
        [$titleMin, $titleMax] = self::range('title');
        [$descriptionMin, $descriptionMax] = self::range('description');

        return strtr($label, [
            ':title' => "{$titleMin}–{$titleMax}",
            ':description' => "{$descriptionMin}–{$descriptionMax}",
        ]);
    }
}
