<?php

use App\Audit\Actions\LogAuditActivityAction;
use App\Models\Activity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * Fallback only. The scheduled `app:audit-prune` command uses the retention
     * chosen in General settings (see config/audit.php for its bounds).
     */
    'clean_after_days' => 180,

    /*
     * Log name used when an activity is written without a category.
     */
    'default_log_name' => 'system',

    /*
     * The API authenticates with Sanctum tokens, so the causer is the Sanctum user.
     */
    'default_auth_driver' => 'sanctum',

    'include_soft_deleted_subjects' => false,

    'activity_model' => Activity::class,

    /*
     * Never written to attribute_changes, for any model. LogAuditActivityAction
     * additionally redacts the keys listed in config/audit.php.
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

    /*
     * Buffered activities have no id until flushed, which breaks the realtime
     * broadcast. Leave this off.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogAuditActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
