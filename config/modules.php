<?php

// Optional-feature toggles for this starter template. Each key has a
// matching NEXT_PUBLIC_MODULE_* flag on the Next.js app — disabling a
// module here stops its scheduled jobs/routes; disabling it on the Next.js
// side only hides its admin UI. Keep both sides in sync for a clean cut.
//
// Unset env var = enabled (opt out per module, not in), so existing
// deployments are unaffected until someone explicitly disables a module.
return [
    'media' => env('MODULE_MEDIA', true),
    'backups' => env('MODULE_BACKUPS', true),
    'seo_health' => env('MODULE_SEO_HEALTH', true),
    'audit' => env('MODULE_AUDIT', true),
    'realtime' => env('MODULE_REALTIME', true),
];
