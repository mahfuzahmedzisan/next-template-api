<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'frontend' => [
        /*
         * Comma-separated browser origins allowed to use this API through the
         * Next.js BFF. FRONTEND_URL remains a single-origin fallback.
         * Example: https://next.maktechlaravel.cloud,http://localhost:3000
         */
        'urls' => array_values(array_filter(array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', (string) env('FRONTEND_URLS', env('FRONTEND_URL', '')))
        ))),

        'bff_secret' => env('BFF_SECRET'),

        /*
         * Next.js route that drops its cached SEO data when Laravel changes it
         * outside a Next request (jobs, commands). Leave empty until deployed.
         * Example: https://example.com/api/internal/revalidate
         */
        'revalidate_url' => env('FRONTEND_REVALIDATE_URL'),
    ],

];
