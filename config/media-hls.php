<?php

return [

    /*
     * Public tus endpoint returned to the browser. Bytes never pass through
     * the Next.js BFF. Override when APP_URL is not the public API host.
     */
    'endpoint' => env('TUS_PUBLIC_ENDPOINT'),

    /*
     * Shared secret tusd sends on hook requests (query string, localhost only).
     * Tests and operators may also send it as X-Tus-Hook-Secret.
     */
    'hook_secret' => env('TUS_HOOK_SECRET'),

    'upload_dir' => env('TUS_UPLOAD_DIR', storage_path('app/tus')),

    'max_bytes' => (int) env('VIDEO_MAX_UPLOAD_BYTES', 8 * 1024 * 1024 * 1024),

    /*
     * Ceiling per kind, still capped by max_bytes. Images and documents are
     * decoded or scanned in PHP, so they get far smaller limits than video.
     */
    'max_bytes_by_kind' => [
        'image' => (int) env('MEDIA_MAX_IMAGE_BYTES', 50 * 1024 * 1024),
        'video' => (int) env('MEDIA_MAX_VIDEO_BYTES', 8 * 1024 * 1024 * 1024),
        'audio' => (int) env('MEDIA_MAX_AUDIO_BYTES', 1024 * 1024 * 1024),
        'pdf' => (int) env('MEDIA_MAX_PDF_BYTES', 200 * 1024 * 1024),
        'document' => (int) env('MEDIA_MAX_DOCUMENT_BYTES', 100 * 1024 * 1024),
    ],

    'token_ttl_seconds' => (int) env('VIDEO_UPLOAD_TOKEN_TTL', 6 * 60 * 60),

    'abandon_after_hours' => (int) env('VIDEO_ABANDON_AFTER_HOURS', 24),

    /* A Processing item with no progress save for this long counts as a dead transcode and may be retried. */
    'stale_processing_minutes' => (int) env('VIDEO_STALE_PROCESSING_MINUTES', 30),

    /*
     * Optional ClamAV daemon, for example unix:///var/run/clamav/clamd.ctl
     * or tcp://127.0.0.1:3310. Empty skips the scan.
     */
    'clamav_socket' => env('CLAMAV_SOCKET'),

];
