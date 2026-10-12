<?php

namespace App\Services\Seo\Audit;

/**
 * Every check the SEO health scan runs, with its weight in the page score.
 *
 * - informational: shown on its own, weight 1, Google doesn't rank on it.
 * - environment: depends on the server (TLS, HSTS, compression); on a
 *   development scan it is stored but left out of the score, like performance.
 */
final class SeoCheckCatalog
{
    public const CATEGORIES = [
        'meta' => 'Meta',
        'content' => 'Content',
        'configuration' => 'Configuration',
        'performance' => 'Performance',
    ];

    public const PRIORITY_RANK = ['high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * @var array<string, array{label: string, category: string, weight: int, priority: string, fix_minutes: int, informational?: bool, environment?: bool}>
     */
    public const CHECKS = [
        'title_present' => ['label' => 'Page has a title', 'category' => 'meta', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'title_length' => ['label' => 'Title is 30–60 characters', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'description_present' => ['label' => 'Page has a meta description', 'category' => 'meta', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'description_length' => ['label' => 'Meta description is 70–160 characters', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'canonical' => ['label' => 'One absolute canonical link', 'category' => 'meta', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 5],
        'robots_meta' => ['label' => 'Search engines may index the page', 'category' => 'meta', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'og_tags' => ['label' => 'Open Graph title, description and URL', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'og_image' => ['label' => 'Social share image', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'twitter_card' => ['label' => 'X (Twitter) card', 'category' => 'meta', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 5],
        'lang' => ['label' => 'Page language is set', 'category' => 'meta', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 5],
        'viewport' => ['label' => 'Mobile viewport', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 5],
        'charset' => ['label' => 'Character set is declared', 'category' => 'meta', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5],
        'structured_data' => ['label' => 'Valid structured data (JSON-LD)', 'category' => 'meta', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 15],
        'keywords_meta' => ['label' => 'Meta keywords', 'category' => 'meta', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5, 'informational' => true],
        'author_meta' => ['label' => 'Meta author', 'category' => 'meta', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 5, 'informational' => true],

        'h1_single' => ['label' => 'Exactly one H1 heading', 'category' => 'content', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 10],
        'h2_present' => ['label' => 'Has H2 subheadings', 'category' => 'content', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 10],
        'image_alt' => ['label' => 'Images have alt text', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],
        'content_length' => ['label' => 'Enough text content', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 30],
        'internal_links' => ['label' => 'Links to other pages on the site', 'category' => 'content', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 10],
        'broken_links' => ['label' => 'No broken internal links', 'category' => 'content', 'weight' => 4, 'priority' => 'high', 'fix_minutes' => 15],
        'broken_images' => ['label' => 'No broken images', 'category' => 'content', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 10],

        'status_ok' => ['label' => 'Page answers 200 OK', 'category' => 'configuration', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 15],
        'robots_txt_allows' => ['label' => 'robots.txt allows the page', 'category' => 'configuration', 'weight' => 5, 'priority' => 'high', 'fix_minutes' => 5],
        'https' => ['label' => 'Served over HTTPS', 'category' => 'configuration', 'weight' => 3, 'priority' => 'high', 'fix_minutes' => 30, 'environment' => true],
        'hsts' => ['label' => 'HSTS header', 'category' => 'configuration', 'weight' => 1, 'priority' => 'low', 'fix_minutes' => 10, 'environment' => true],
        'compression' => ['label' => 'Compressed response', 'category' => 'configuration', 'weight' => 2, 'priority' => 'medium', 'fix_minutes' => 10, 'environment' => true],

        'ttfb' => ['label' => 'Fast server response', 'category' => 'performance', 'weight' => 3, 'priority' => 'medium', 'fix_minutes' => 60],
        'html_size' => ['label' => 'Small HTML document', 'category' => 'performance', 'weight' => 2, 'priority' => 'low', 'fix_minutes' => 30],
    ];

    /** Checks run on a noindex page when it is included: configuration only, never scored. */
    public const NOINDEX_CHECKS = ['status_ok', 'title_present', 'robots_meta'];

    /**
     * @return array{label: string, category: string, weight: int, priority: string, fix_minutes: int, informational: bool, environment: bool}
     */
    public static function get(string $key): array
    {
        $check = self::CHECKS[$key];

        return [
            ...$check,
            'informational' => $check['informational'] ?? false,
            'environment' => $check['environment'] ?? false,
        ];
    }

    /**
     * The checks that apply to a page. Detail-only checks (content length)
     * run on records only; config can skip checks for a static page type.
     *
     * @return list<string>
     */
    public static function forPage(string $kind, string $pageType, bool $indexable): array
    {
        if (! $indexable) {
            return self::NOINDEX_CHECKS;
        }

        $detailOnly = (array) config('seo.audit.detail_only', []);
        $skip = $kind === 'page' ? (array) (config('seo.audit.skip')[$pageType] ?? []) : [];

        return array_values(array_filter(
            array_keys(self::CHECKS),
            static fn (string $key) => ! in_array($key, $skip, true)
                && ($kind === 'model' || ! in_array($key, $detailOnly, true)),
        ));
    }
}
