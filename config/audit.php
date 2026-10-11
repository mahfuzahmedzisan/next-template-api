<?php

use App\Models\MediaItem;
use App\Models\User;

return [

    /*
     * Retention is chosen in General settings and clamped to these bounds.
     */
    'retention' => [
        'min_days' => 30,
        'max_days' => 730,
        'default_days' => 180,
    ],

    'prune' => [
        'time' => env('AUDIT_PRUNE_TIME', '02:00'),
        'timezone' => env('AUDIT_PRUNE_TIMEZONE', 'Asia/Dhaka'),
    ],

    /*
     * Values whose key matches one of these (case-insensitive, anywhere in the
     * key) are stored as "[redacted]" in attribute_changes and properties.
     */
    'redact' => [
        'password',
        'token',
        'secret',
        'credential',
        'recovery_code',
        'api_key',
    ],

    /*
     * Short aliases used by the API and the admin UI to refer to subject types.
     * Database rows keep the full class name (no enforced morph map).
     */
    'subjects' => [
        'user' => User::class,
        'media' => MediaItem::class,
    ],

    'broadcast' => (bool) env('AUDIT_BROADCAST', true),

    'export_max_rows' => (int) env('AUDIT_EXPORT_MAX_ROWS', 50000),
];
