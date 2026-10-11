<?php

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Audit\RestorePreserver;
use App\Events\ActivityLogged;
use App\Models\Activity;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

uses(RefreshDatabase::class);

const AUDIT_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

function auditAdmin(): User
{
    return User::factory()->admin()->create(['name' => 'Audit Admin']);
}

function auditMember(array $attributes = []): User
{
    return User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'Password1!',
        ...$attributes,
    ]);
}

function lastActivity(string $event): ?Activity
{
    return Activity::query()->where('event', $event)->latest('id')->first();
}

it('records a successful sign-in with the forwarded ip, device and request id', function (): void {
    $user = auditMember();
    $requestId = (string) Str::uuid();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'member@example.com',
        'password' => 'Password1!',
    ], [
        'X-Client-IP' => '203.0.113.9',
        'X-Client-User-Agent' => AUDIT_AGENT,
        'X-Request-Id' => $requestId,
    ])->assertOk()->assertHeader('X-Request-Id', $requestId);

    $activity = lastActivity(AuditEvent::LoginSucceeded->value);

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('auth')
        ->and($activity->severity)->toBe('info')
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->subject_id)->toBe($user->id)
        ->and($activity->causer_label)->toBe($user->name)
        ->and($activity->ip_address)->toBe('203.0.113.9')
        ->and($activity->user_agent)->toBe(AUDIT_AGENT)
        ->and($activity->request_id)->toBe($requestId)
        ->and($activity->origin)->toBe('web');
});

it('records failed sign-ins for known and unknown emails without a causer', function (): void {
    $user = auditMember();

    $this->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'wrong'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'wrong'])->assertUnprocessable();

    $failures = Activity::query()->where('event', AuditEvent::LoginFailed->value)->oldest('id')->get();

    expect($failures)->toHaveCount(2)
        ->and($failures[0]->severity)->toBe('warning')
        ->and($failures[0]->causer_id)->toBeNull()
        ->and($failures[0]->subject_id)->toBe($user->id)
        ->and($failures[0]->properties['reason'])->toBe('wrong_password')
        ->and($failures[1]->subject_id)->toBeNull()
        ->and($failures[1]->subject_label)->toBe('ghost@example.com')
        ->and($failures[1]->properties['reason'])->toBe('unknown_email')
        ->and($failures[1]->properties->toArray())->not->toHaveKey('password');
});

it('records login throttling once per minute', function (): void {
    auditMember();

    foreach (range(1, 8) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'wrong']);
    }

    expect(Activity::query()->where('event', AuditEvent::LoginThrottled->value)->count())->toBe(1);
});

it('logs only the changed, allowed attributes of an audited model', function (): void {
    $admin = auditAdmin();
    $media = MediaItem::factory()->create(['title' => 'Old title']);

    actingAsSanctum($admin);
    $media->update(['title' => 'New title']);
    $media->forceFill(['progress' => 40])->save();

    $updates = Activity::query()
        ->where('subject_type', MediaItem::class)
        ->where('subject_id', $media->id)
        ->where('event', 'updated')
        ->get();

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->log_name)->toBe('media')
        ->and($updates[0]->attribute_changes['old'])->toBe(['title' => 'Old title'])
        ->and($updates[0]->attribute_changes['attributes'])->toBe(['title' => 'New title'])
        ->and($updates[0]->causer_id)->toBe($admin->id)
        ->and($updates[0]->subject_label)->toBe('New title');
});

it('never writes passwords or two-factor secrets into user changes', function (): void {
    $user = auditMember();

    $user->forceFill(['name' => 'Renamed', 'password' => 'NewPassword1!', 'remember_token' => 'abc'])->save();

    $update = Activity::query()->where('subject_id', $user->id)->where('event', 'updated')->sole();

    expect(array_keys($update->attribute_changes['attributes']))->toBe(['name']);
});

it('masks sensitive keys in hand-written properties', function (): void {
    Audit::log(AuditEvent::BackupRequested, properties: [
        'api_key' => 'live-123',
        'nested' => ['client_secret' => 'shh', 'kind' => 'manual'],
    ]);

    $activity = lastActivity(AuditEvent::BackupRequested->value);

    expect($activity->properties['api_key'])->toBe('[redacted]')
        ->and($activity->properties['nested']['client_secret'])->toBe('[redacted]')
        ->and($activity->properties['nested']['kind'])->toBe('manual');
});

it('records two-factor changes announced by fortify', function (): void {
    $user = auditMember();
    actingAsSanctum($user);

    event(new TwoFactorAuthenticationConfirmed($user));

    expect(lastActivity(AuditEvent::TwoFactorConfirmed->value))
        ->log_name->toBe('security')
        ->causer_id->toBe($user->id);
});

it('keeps an account deletion entry after the user is gone', function (): void {
    $user = auditMember(['name' => 'Leaving User']);

    actingAsSanctum($user)
        ->deleteJson('/api/v1/profile', ['current_password' => 'Password1!'])
        ->assertOk();

    $activity = lastActivity(AuditEvent::AccountDeleted->value);

    expect($activity)->severity->toBe('critical')
        ->causer_label->toBe('Leaving User')
        ->and(Activity::query()->where('subject_id', $user->id)->where('event', 'deleted')->exists())->toBeFalse();
});

it('saves the retention setting inside its bounds and audits the change', function (): void {
    $admin = auditAdmin();

    actingAsSanctum($admin)
        ->putJson('/api/v1/admin/settings/general', ['registration_enabled' => true, 'audit_retention_days' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('audit_retention_days');

    actingAsSanctum($admin)
        ->putJson('/api/v1/admin/settings/general', ['registration_enabled' => true, 'audit_retention_days' => 1000])
        ->assertUnprocessable();

    actingAsSanctum($admin)
        ->putJson('/api/v1/admin/settings/general', ['registration_enabled' => true, 'audit_retention_days' => 365])
        ->assertOk()
        ->assertJsonPath('data.settings.general.audit_retention_days', 365);

    $activity = lastActivity(AuditEvent::GeneralSettingsUpdated->value);

    expect($activity->attribute_changes['old']['audit_retention_days'])->toBe(180)
        ->and($activity->attribute_changes['attributes']['audit_retention_days'])->toBe(365);
});

it('records that the smtp password changed without storing it', function (): void {
    actingAsSanctum(auditAdmin())
        ->putJson('/api/v1/admin/settings/smtp', [
            'enabled' => false,
            'host' => 'smtp.example.com',
            'password' => 'hunter2-secret',
        ])
        ->assertOk();

    $activity = lastActivity(AuditEvent::SmtpSettingsUpdated->value);

    expect($activity->attribute_changes['attributes']['password'])->toBe('[redacted]')
        ->and($activity->attribute_changes['attributes']['host'])->toBe('smtp.example.com')
        ->and(json_encode($activity->getAttributes()))->not->toContain('hunter2-secret');
});

it('lets only admins read the audit log', function (): void {
    actingAsSanctum(auditMember())->getJson('/api/v1/admin/audit')->assertForbidden();
    actingAsSanctum(auditMember(['email' => 'other@example.com']))->getJson('/api/v1/admin/audit/export')->assertForbidden();
});

it('filters the audit log by category, severity, subject and text', function (): void {
    $admin = auditAdmin();
    $media = MediaItem::factory()->create(['title' => 'Launch video']);

    Audit::log(AuditEvent::BackupFailed, properties: ['reason' => 'disk full'], subjectLabel: 'manual-x.zip');
    Audit::log(AuditEvent::MediaRetried, subject: $media);

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit?filter[category]=backups&filter[severity]=critical')
        ->assertOk()
        ->assertJsonCount(1, 'data.activities')
        ->assertJsonPath('data.activities.0.event', 'backup.failed')
        ->assertJsonPath('data.activities.0.title', 'Backup failed')
        ->assertJsonPath('data.activities.0.subject.label', 'manual-x.zip');

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit?filter[subject]=media:'.$media->id)
        ->assertOk()
        ->assertJsonPath('data.meta.total', 2);

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit?filter[q]=Launch&filter[event]=media.retried')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.activities.0.subject.type', 'media');
});

it('shows one entry with its field changes and related request entries', function (): void {
    $admin = auditAdmin();
    $media = MediaItem::factory()->create(['title' => 'Before']);

    actingAsSanctum($admin);
    $media->update(['title' => 'After', 'alt' => 'Alt text']);

    $activity = Activity::query()->where('event', 'updated')->where('subject_id', $media->id)->sole();

    actingAsSanctum($admin)
        ->getJson("/api/v1/admin/audit/{$activity->id}")
        ->assertOk()
        ->assertJsonPath('data.activity.title', 'Media item updated')
        ->assertJsonPath('data.activity.changes.0.field', 'title')
        ->assertJsonPath('data.activity.changes.0.old', 'Before')
        ->assertJsonPath('data.activity.changes.0.new', 'After');
});

it('returns overview counts, retention and filter options', function (): void {
    $admin = auditAdmin();
    Audit::log(AuditEvent::LoginFailed, subjectLabel: 'x@example.com');

    actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit/overview')
        ->assertOk()
        ->assertJsonPath('data.retention_days', 180)
        ->assertJsonPath('data.stats.failed_sign_ins_24_hours', 1)
        ->assertJsonPath('data.stats.elevated_7_days', 1)
        ->assertJsonStructure(['data' => ['options' => ['categories', 'severities', 'events', 'subjects']]]);
});

it('exports filtered entries as csv, neutralising formulas, and audits the export', function (): void {
    $admin = auditAdmin();
    Audit::log(AuditEvent::BackupDeleted, subjectLabel: '=HYPERLINK("http://evil")');

    $response = actingAsSanctum($admin)
        ->get('/api/v1/admin/audit/export?filter[category]=backups')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Backup deleted')
        ->toContain("'=HYPERLINK")
        ->not->toContain('Signed in')
        ->and(lastActivity(AuditEvent::AuditExported->value))
        ->properties->toMatchArray(['rows' => 1, 'filters' => ['category' => 'backups']]);
});

it('shows a user only their own personal activity', function (): void {
    $user = auditMember();
    $other = auditMember(['email' => 'someone@example.com']);

    Audit::log(AuditEvent::PasswordChanged, subject: $user, causer: $user);
    Audit::log(AuditEvent::PasswordChanged, subject: $other, causer: $other);
    Audit::log(AuditEvent::BackupRequested, causer: $user);

    $events = collect(
        actingAsSanctum($user)->getJson('/api/v1/profile/activity')->assertOk()->json('data.activities'),
    );

    expect($events->pluck('event'))->toContain('password.changed')
        ->not->toContain('backup.requested')
        ->and($events->pluck('subject.id')->unique()->all())->toBe([$user->id]);
});

it('prunes entries older than the retention set in application settings', function (): void {
    Audit::log(AuditEvent::BackupRequested);
    Activity::query()->update(['created_at' => now()->subDays(200)]);
    Audit::log(AuditEvent::BackupCompleted);

    $this->artisan('app:audit-prune')->assertSuccessful();

    expect(Activity::query()->where('event', AuditEvent::BackupRequested->value)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', AuditEvent::BackupCompleted->value)->exists())->toBeTrue()
        ->and(lastActivity(AuditEvent::AuditPruned->value))
        ->origin->toBe('system')
        ->properties->toMatchArray(['deleted' => 1, 'retention_days' => 180]);

    $this->artisan('app:audit-prune --days=5')->assertExitCode(2);
});

it('puts back entries written after the backup when the database is restored', function (): void {
    $old = Audit::log(AuditEvent::BackupCompleted);
    $old->forceFill(['created_at' => now()->subDays(3)])->save();
    $recent = Audit::log(AuditEvent::RestoreRequested, subjectLabel: 'scheduled.zip');

    $preserver = app(RestorePreserver::class);
    $snapshot = $preserver->capture(now()->subDay());

    // The restored dump contains only the old entry.
    Activity::query()->whereKey($recent->id)->delete();

    expect($preserver->replay($snapshot))->toBe(1)
        ->and(Activity::query()->find($recent->id))->subject_label->toBe('scheduled.zip')
        ->and(Activity::query()->count())->toBe(2);
});

it('broadcasts new entries to admins when enabled', function (): void {
    config(['audit.broadcast' => true]);
    Event::fake([ActivityLogged::class]);

    Audit::log(AuditEvent::BackupRequested);

    Event::assertDispatched(ActivityLogged::class, function (ActivityLogged $event): bool {
        return $event->broadcastOn()[0]->name === 'private-admin.audit'
            && $event->broadcastWith()['activity']['event'] === 'backup.requested';
    });
});
