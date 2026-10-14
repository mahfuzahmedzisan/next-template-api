<?php

namespace App\Listeners\Notifications;

use App\Audit\AuditSeverity;
use App\Events\ActivityLogged;
use App\Models\User;
use App\Notifications\CriticalActivityNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Surfaces critical-severity audit activity (e.g. a failed backup restore)
 * to every admin via the notification bell, in addition to the audit log
 * they already appear in.
 */
class NotifyAdminsOfCriticalActivity
{
    public function handle(ActivityLogged $event): void
    {
        if ($event->activity->severityLevel() !== AuditSeverity::Critical) {
            return;
        }

        Notification::send(
            User::query()->admins()->get(),
            new CriticalActivityNotification($event->activity),
        );
    }
}
