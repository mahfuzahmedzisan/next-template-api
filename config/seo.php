<?php

use App\Models\Post;
use App\Models\Project;

return [

    /*
     * Every SEO job (file regeneration, path probes, frontend revalidation and
     * audits) runs on its own connection. Its retry_after must stay above the
     * audit timeout, or a long audit is released and runs twice.
     */
    'queue' => [
        'connection' => env('SEO_QUEUE_CONNECTION', 'redis-seo'),
        'name' => env('SEO_QUEUE', 'seo'),
    ],

    /*
     * Detail-page models that carry their own SEO through HasSeo, keyed by the
     * morph alias stored in seo_meta.seoable_type. Example:
     *   'blog' => App\Models\Blog::class,
     */
    'seoables' => [
        'project' => Project::class,
        'post' => Post::class,
    ],

    'page_types' => ['home', 'about', 'listing', 'contact', 'landing', 'legal', 'auth', 'other'],

    'schema_types' => ['WebPage', 'AboutPage', 'CollectionPage', 'ContactPage', 'Article', 'CreativeWork', 'Product'],

    /*
     * Generated sitemap.xml, robots.txt and llms.txt live under "seo/" here.
     */
    'files_disk' => env('SEO_FILES_DISK', 'local'),

    'robots' => [
        // Storage images back og:image, and /og is the generated fallback card.
        'always_allow' => ['/api/storage/', '/og'],
        'always_disallow' => ['/admin', '/api/'],
        'ai_crawlers' => ['GPTBot', 'ChatGPT-User', 'ClaudeBot', 'anthropic-ai', 'CCBot', 'Google-Extended', 'PerplexityBot', 'Bytespider', 'Amazonbot', 'Applebot-Extended'],
    ],

    'sitemap' => [
        'max_urls_per_file' => 50000,
    ],

    'cache' => [
        'ttl' => 86400,
    ],

    'probe' => [
        'timeout' => 5,
    ],

    'audit' => [
        /*
         * Next.js only puts streamed metadata in <head> for user agents in its
         * htmlLimitedBots list; the frontend adds this token to that list via
         * its own SEO_SCANNER_UA_TOKEN env var. Both sides must agree on the
         * token (not just this full user agent string), or SEO health
         * scanning breaks silently because the frontend streams an empty
         * <head> to the scanner.
         */
        'user_agent' => env(
            'SEO_SCANNER_USER_AGENT',
            'Mozilla/5.0 (compatible; '.env('SEO_SCANNER_UA_TOKEN', 'TemplateSeoScanner').'/1.0)'
        ),
        // Each page is fetched a second time as a regular browser: crawlers outside the
        // htmlLimitedBots list get the same HTML, so metadata must be in <head> for it too.
        'browser_user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        'timeout' => (int) env('SEO_AUDIT_REQUEST_TIMEOUT', 15),
        'warmup_timeout' => 60,
        'job_timeout' => 900,
        'unique_for' => 1200,
        'link_timeout' => 5,
        'concurrency' => 5,
        'chunk_size' => 10,
        'batch_threshold' => 25,
        'limit_per_type' => (int) env('SEO_AUDIT_LIMIT_PER_TYPE', 200),
        'max_links' => 25,
        'max_images' => 20,
        'keep' => 20,
        'ttfb_ms' => 800,
        'max_html_kb' => 512,

        /*
         * The strict profile: the strictest rule the common third-party checkers
         * (Semrush, Backlinko, Seobility, SEOptimer, Sitechecker, Rank Math) apply.
         * The admin SEO tab reads the title and description ranges from here.
         */
        'thresholds' => [
            'title' => [50, 60],
            'description' => [140, 160],
            // Minimum words of visible text, by static page type or model alias.
            'min_words' => [
                'home' => 800,
                'about' => 600,
                'landing' => 600,
                'post' => 600,
                'legal' => 500,
                'project' => 400,
                'listing' => 300,
                'contact' => 300,
                'default' => 300,
            ],
            'text_ratio' => 10,
            'min_h2' => 2,
            'min_h3' => 1,
            'min_internal_links' => 5,
            'og_image' => [
                'min_width' => 1200,
                'min_height' => 628,
                // 1.91:1. The tolerance is tight on purpose: 1200x630 (1.905) is flagged by checkers.
                'ratio' => 1.91,
                'ratio_tolerance' => 0.005,
                'max_bytes' => 5 * 1024 * 1024,
            ],
        ],

        'site' => [
            'favicon_sizes' => [16, 32, 48],
            'apple_touch_size' => 180,
            'manifest_icon_sizes' => [192, 512],
            // Each must answer a permanent redirect (301 or 308) to the home page.
            'index_paths' => ['/index.html', '/index.php', '/home'],
        ],

        'robots' => [
            'max_kb' => 500,
            // Crawlers must reach these to render pages (Next.js assets, images, the OG card).
            'asset_paths' => ['/_next/static/chunks/main.js', '/_next/static/css/app.css', '/_next/image', '/og', '/api/storage/seo/image.jpg', '/favicon.ico'],
            'agents' => ['*', 'googlebot', 'bingbot'],
        ],

        'sitemap' => [
            // URLs fetched per audit to confirm they answer 200 and are indexable.
            'url_limit' => 200,
            'max_files' => 50,
            'max_bytes' => 50 * 1024 * 1024,
            'max_urls' => 50000,
            'max_images' => 20,
        ],

        /*
         * Checks skipped for a given static page type.
         */
        'skip' => [
            'auth' => ['headings', 'internal_links', 'external_links', 'og_image', 'og_image_meta', 'structured_data', 'breadcrumbs', 'content_length', 'text_ratio'],
        ],
    ],
];
