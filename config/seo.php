<?php

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
    'seoables' => [],

    'page_types' => ['home', 'about', 'listing', 'contact', 'landing', 'legal', 'auth', 'other'],

    'schema_types' => ['WebPage', 'AboutPage', 'CollectionPage', 'ContactPage', 'Article', 'Product'],

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
         * htmlLimitedBots list; the frontend adds this token to that list.
         */
        'user_agent' => env('SEO_SCANNER_USER_AGENT', 'Mozilla/5.0 (compatible; MahfuzSeoScanner/1.0)'),
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
        'detail_min_words' => 300,

        /*
         * Checks that only make sense on detail pages (an article or a product),
         * and checks skipped for a given static page type.
         */
        'detail_only' => ['content_length'],
        'skip' => [
            'auth' => ['h2_present', 'internal_links', 'og_image', 'structured_data'],
        ],
    ],
];
