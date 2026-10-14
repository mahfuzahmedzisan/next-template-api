<?php

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Events\ActivityLogged;
use App\Listeners\Notifications\NotifyAdminsOfBackupFailure;
use App\Listeners\Notifications\NotifyAdminsOfCriticalActivity;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Notifications\CriticalActivityNotification;
use App\Notifications\SeoHealthScoreDroppedNotification;
use App\Services\Seo\Audit\SeoAuditRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

uses(RefreshDatabase::class);

function notificationsAdmin(array $attributes = []): User
{
    return User::factory()->admin()->create($attributes);
}

/**
 * A real, persisted critical activity — notifications are queued (even the
 * sync driver round-trips them through serialization), and that restores an
 * Eloquent property by re-querying its primary key, so the model it carries
 * must actually exist in the database.
 */
function criticalActivity(): Activity
{
    return Audit::log(AuditEvent::RestoreFailed, subjectLabel: 'scheduled.zip');
}

// -- Preference gating (AdminAlertNotification::via) -------------------------------------------

it('sends database and broadcast only when push_notifications is on', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => true, 'email_notifications' => false]);

    $admin->notify(new CriticalActivityNotification(criticalActivity()));

    Notification::assertSentTo($admin, CriticalActivityNotification::class, function (CriticalActivityNotification $notification, array $channels) {
        return $channels === ['database', 'broadcast'];
    });
});

it('sends mail only when email_notifications is on', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => false, 'email_notifications' => true]);

    $admin->notify(new CriticalActivityNotification(criticalActivity()));

    Notification::assertSentTo($admin, CriticalActivityNotification::class, function (CriticalActivityNotification $notification, array $channels) {
        return $channels === ['mail'];
    });
});

it('sends nothing when both notification preferences are off', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => false, 'email_notifications' => false]);

    $admin->notify(new CriticalActivityNotification(criticalActivity()));

    Notification::assertNotSentTo($admin, CriticalActivityNotification::class);
});

// -- Event wiring --------------------------------------------------------------------------------

it('notifies every admin when a critical activity is logged, but not members', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => true]);
    $member = User::factory()->create(['push_notifications' => true]);

    (new NotifyAdminsOfCriticalActivity)->handle(new ActivityLogged(criticalActivity()));

    Notification::assertSentTo($admin, CriticalActivityNotification::class);
    Notification::assertNotSentTo($member, CriticalActivityNotification::class);
});

it('ignores non-critical activity', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => true]);
    $activity = Audit::log(AuditEvent::LoginSucceeded);

    (new NotifyAdminsOfCriticalActivity)->handle(new ActivityLogged($activity));

    Notification::assertNothingSentTo($admin);
});

it('notifies admins when a backup run fails', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => true]);

    (new NotifyAdminsOfBackupFailure)->handleFailed(new BackupHasFailed(new Exception('Disk quota exceeded.')));

    Notification::assertSentTo($admin, BackupFailedNotification::class, function (BackupFailedNotification $notification) {
        return $notification->reason === 'Disk quota exceeded.';
    });
});

it('notifies admins when a backup health check finds a problem', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => true]);

    (new NotifyAdminsOfBackupFailure)->handleUnhealthy(new UnhealthyBackupWasFound(
        'local',
        'default',
        new Collection([['check' => 'size', 'message' => 'The newest backup is too small.']]),
    ));

    Notification::assertSentTo($admin, BackupFailedNotification::class, function (BackupFailedNotification $notification) {
        return $notification->reason === 'The newest backup is too small.';
    });
});

it('notifies admins on a meaningful SEO health score drop but not on minor noise', function (): void {
    Notification::fake();

    $admin = notificationsAdmin(['push_notifications' => true]);
    $runner = app(SeoAuditRunner::class);
    $method = (new ReflectionClass($runner))->getMethod('notifyOnScoreDrop');

    $method->invoke($runner, 90, 80);
    Notification::assertSentTo($admin, SeoHealthScoreDroppedNotification::class, function (SeoHealthScoreDroppedNotification $notification) {
        return $notification->previousScore === 90 && $notification->currentScore === 80;
    });

    Notification::fake();
    $method->invoke($runner, 90, 85);
    Notification::assertNothingSentTo($admin);

    Notification::fake();
    $method->invoke($runner, null, 85);
    Notification::assertNothingSentTo($admin);
});

it('broadcasts under a clean `.notification` event name on the same private channel the video bell uses', function (): void {
    $admin = notificationsAdmin(['push_notifications' => true]);

    $notification = new CriticalActivityNotification(criticalActivity());
    $broadcastEvent = new BroadcastNotificationCreated(
        $admin,
        $notification,
        $notification->toBroadcast($admin)->data,
    );

    expect($broadcastEvent->broadcastAs())->toBe('notification')
        ->and($broadcastEvent->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$admin->id);
});

// -- Inbox endpoints ------------------------------------------------------------------------------

it("lists, counts and marks the signed-in user's own notifications", function (): void {
    $admin = notificationsAdmin(['push_notifications' => true]);
    $admin->notify(new CriticalActivityNotification(criticalActivity()));
    $admin->notify(new CriticalActivityNotification(criticalActivity()));

    actingAsSanctum($admin)->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 2);

    $list = actingAsSanctum($admin)->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data.notifications');

    $id = $list->json('data.notifications.0.id');

    actingAsSanctum($admin)->postJson("/api/v1/notifications/{$id}/read")->assertOk();

    actingAsSanctum($admin)->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    actingAsSanctum($admin)->postJson('/api/v1/notifications/read-all')->assertOk();

    actingAsSanctum($admin)->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});

it("refuses to read or delete another user's notification", function (): void {
    $admin = notificationsAdmin(['push_notifications' => true]);
    $admin->notify(new CriticalActivityNotification(criticalActivity()));
    $notificationId = $admin->notifications()->first()->id;

    $other = notificationsAdmin(['push_notifications' => true]);

    actingAsSanctum($other)->postJson("/api/v1/notifications/{$notificationId}/read")
        ->assertStatus(422);

    actingAsSanctum($other)->deleteJson("/api/v1/notifications/{$notificationId}")
        ->assertStatus(422);

    expect($admin->notifications()->first()->read_at)->toBeNull();
});

it('deletes a notification belonging to the signed-in user', function (): void {
    $admin = notificationsAdmin(['push_notifications' => true]);
    $admin->notify(new CriticalActivityNotification(criticalActivity()));
    $notificationId = $admin->notifications()->first()->id;

    actingAsSanctum($admin)->deleteJson("/api/v1/notifications/{$notificationId}")->assertOk();

    expect($admin->notifications()->count())->toBe(0);
});
