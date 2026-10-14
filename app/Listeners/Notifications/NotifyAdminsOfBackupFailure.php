<?php

namespace App\Listeners\Notifications;

use App\Models\User;
use App\Notifications\BackupFailedNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * spatie/laravel-backup fires these from the scheduled `backup:run` and
 * `backup:monitor` commands, not from a request — there is no one to show
 * an error page to, so the only way an admin finds out is this notification.
 */
class NotifyAdminsOfBackupFailure
{
    public function handleFailed(BackupHasFailed $event): void
    {
        $this->notify($event->exception->getMessage());
    }

    public function handleUnhealthy(UnhealthyBackupWasFound $event): void
    {
        $messages = $event->failureMessages
            ->pluck('message')
            ->implode(' ');

        $this->notify($messages !== '' ? $messages : 'The backup health check reported a problem.');
    }

    private function notify(string $reason): void
    {
        Notification::send(
            User::query()->admins()->get(),
            new BackupFailedNotification($reason),
        );
    }
}
