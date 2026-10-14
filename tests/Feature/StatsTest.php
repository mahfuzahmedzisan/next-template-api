<?php

use App\Audit\AuditEvent;
use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Backups\BackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
});

function statsAdmin(): User
{
    return User::factory()->admin()->create(['name' => 'Stats Admin']);
}

/**
 * Inserts audit rows directly so the counts are exact and no model events fire.
 *
 * @param  array<string, mixed>  $attributes
 */
function logActivity(string $at, array $attributes = []): void
{
    DB::table('activity_log')->insert([
        'log_name' => 'auth',
        'description' => 'Test entry',
        'event' => AuditEvent::LoginSucceeded->value,
        'severity' => 'info',
        'created_at' => Carbon::parse($at, 'UTC'),
        'updated_at' => Carbon::parse($at, 'UTC'),
        ...$attributes,
    ]);
}

/**
 * @param  list<array<string, int|string>>  $points
 * @return array<string, int|string>
 */
function pointFor(array $points, string $date): array
{
    return collect($points)->firstWhere('date', $date) ?? [];
}

it('returns one zero-filled point per day for the audit trend', function (): void {
    $admin = statsAdmin();
    DB::table('activity_log')->delete();

    logActivity('2026-10-14 09:00:00', ['severity' => 'warning', 'log_name' => 'security']);
    logActivity('2026-10-12 09:00:00');
    logActivity('2026-09-01 09:00:00');

    $data = actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit/trend?days=7&tz=UTC')
        ->assertOk()
        ->assertJsonPath('data.days', 7)
        ->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.from', '2026-10-08')
        ->assertJsonPath('data.to', '2026-10-14')
        ->json('data');

    expect($data['daily'])->toHaveCount(7)
        ->and(pointFor($data['daily'], '2026-10-14'))->toMatchArray(['warning' => 1, 'info' => 0, 'notice' => 0, 'critical' => 0])
        ->and(pointFor($data['daily'], '2026-10-12'))->toMatchArray(['info' => 1])
        ->and(pointFor($data['daily'], '2026-10-10'))->toMatchArray(['info' => 0, 'warning' => 0])
        ->and(pointFor($data['daily_categories'], '2026-10-14'))->toMatchArray(['security' => 1, 'auth' => 0])
        ->and(collect($data['by_category'])->firstWhere('value', 'auth'))->toMatchArray(['label' => 'Sign-in', 'count' => 1])
        ->and(collect($data['by_category'])->firstWhere('value', 'security')['count'])->toBe(1);
});

it('splits sign-ins into succeeded and failed', function (): void {
    $admin = statsAdmin();
    DB::table('activity_log')->delete();

    logActivity('2026-10-14 08:00:00');
    logActivity('2026-10-14 08:05:00', ['event' => AuditEvent::LoginFailed->value, 'severity' => 'warning']);
    logActivity('2026-10-14 08:06:00', ['event' => AuditEvent::LoginThrottled->value, 'severity' => 'warning']);
    logActivity('2026-10-14 08:07:00', ['event' => AuditEvent::TwoFactorChallengeFailed->value, 'log_name' => 'security']);
    logActivity('2026-10-14 08:08:00', ['event' => AuditEvent::Logout->value]);

    $signIns = actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit/trend?days=7&tz=UTC')
        ->assertOk()
        ->json('data.sign_ins');

    expect(pointFor($signIns, '2026-10-14'))->toBe(['date' => '2026-10-14', 'succeeded' => 1, 'failed' => 3]);
});

it('folds hours into the viewer timezone day', function (): void {
    $admin = statsAdmin();
    DB::table('activity_log')->delete();

    // 20:00 UTC on the 13th is 02:00 on the 14th in Dhaka (UTC+6).
    logActivity('2026-10-13 20:00:00');

    $utc = actingAsSanctum($admin)->getJson('/api/v1/admin/audit/trend?days=7&tz=UTC')->json('data.daily');
    $dhaka = actingAsSanctum($admin)->getJson('/api/v1/admin/audit/trend?days=7&tz=Asia/Dhaka')
        ->assertJsonPath('data.timezone', 'Asia/Dhaka')
        ->json('data.daily');

    expect(pointFor($utc, '2026-10-13')['info'])->toBe(1)
        ->and(pointFor($utc, '2026-10-14')['info'])->toBe(0)
        ->and(pointFor($dhaka, '2026-10-13')['info'])->toBe(0)
        ->and(pointFor($dhaka, '2026-10-14')['info'])->toBe(1);
});

it('splits an hour across local midnight in half-hour and quarter-hour zones', function (string $tz, string $before, string $after): void {
    $admin = statsAdmin();
    DB::table('activity_log')->delete();

    logActivity($before);
    logActivity($after);

    $daily = actingAsSanctum($admin)
        ->getJson('/api/v1/admin/audit/trend?days=7&tz='.$tz)
        ->assertJsonPath('data.timezone', $tz)
        ->json('data.daily');

    expect(pointFor($daily, '2026-10-13')['info'])->toBe(1)
        ->and(pointFor($daily, '2026-10-14')['info'])->toBe(1);
})->with([
    // 18:29 UTC is 23:59 in Kolkata; 18:30 UTC is 00:00 the next day.
    'Asia/Kolkata' => ['Asia/Kolkata', '2026-10-13 18:29:59', '2026-10-13 18:30:00'],
    // 18:14 UTC is 23:59 in Kathmandu; 18:15 UTC is 00:00 the next day.
    'Asia/Kathmandu' => ['Asia/Kathmandu', '2026-10-13 18:14:59', '2026-10-13 18:15:00'],
]);

it('validates the range and falls back to the user then UTC timezone', function (): void {
    $admin = statsAdmin();

    actingAsSanctum($admin)->getJson('/api/v1/admin/audit/trend?days=14')->assertUnprocessable();

    actingAsSanctum($admin)->getJson('/api/v1/admin/audit/trend?tz=Mars/Base')
        ->assertOk()
        ->assertJsonPath('data.days', 30)
        ->assertJsonPath('data.timezone', 'UTC');

    $admin->forceFill(['timezone' => 'Asia/Dhaka'])->save();

    actingAsSanctum($admin->fresh())->getJson('/api/v1/admin/audit/trend?days=90')
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Dhaka')
        ->assertJsonCount(90, 'data.daily');
});

it('keeps admin stats away from members', function (string $url): void {
    actingAsSanctum(User::factory()->create())->getJson($url)->assertForbidden();
})->with([
    '/api/v1/admin/audit/trend',
    '/api/v1/admin/media/stats',
    '/api/v1/admin/backups/history',
]);

it('summarises the media library by kind, status and upload day', function (): void {
    $admin = statsAdmin();

    MediaItem::factory()->ready()->create(['size' => 1000, 'uploaded_by' => $admin->id]);
    MediaItem::factory()->audio()->create(['size' => 250, 'status' => VideoStatus::Failed, 'uploaded_by' => $admin->id]);
    MediaItem::factory()->create([
        'kind' => MediaKind::Image,
        'size' => null,
        'uploaded_by' => $admin->id,
        'created_at' => Carbon::parse('2026-10-10 10:00:00', 'UTC'),
    ]);

    $data = actingAsSanctum($admin)
        ->getJson('/api/v1/admin/media/stats?days=7&tz=UTC')
        ->assertOk()
        ->assertJsonPath('data.totals', ['count' => 3, 'bytes' => 1250])
        ->json('data');

    expect(collect($data['by_kind'])->firstWhere('value', 'video'))->toBe(['value' => 'video', 'count' => 1, 'bytes' => 1000])
        ->and(collect($data['by_kind'])->firstWhere('value', 'pdf'))->toBe(['value' => 'pdf', 'count' => 0, 'bytes' => 0])
        ->and(collect($data['by_status'])->firstWhere('value', 'failed')['count'])->toBe(1)
        ->and(collect($data['by_status'])->firstWhere('value', 'ready')['count'])->toBe(1)
        ->and($data['uploads'])->toHaveCount(7)
        ->and(pointFor($data['uploads'], '2026-10-14'))->toMatchArray(['video' => 1, 'audio' => 1, 'image' => 0])
        ->and(pointFor($data['uploads'], '2026-10-10'))->toMatchArray(['image' => 1]);
});

it('refreshes cached media stats after an upload or delete', function (): void {
    $admin = statsAdmin();

    actingAsSanctum($admin)->getJson('/api/v1/admin/media/stats')->assertJsonPath('data.totals.count', 0);

    $item = MediaItem::factory()->create(['uploaded_by' => $admin->id]);
    actingAsSanctum($admin)->getJson('/api/v1/admin/media/stats')->assertJsonPath('data.totals.count', 1);

    $item->delete();
    actingAsSanctum($admin)->getJson('/api/v1/admin/media/stats')->assertJsonPath('data.totals.count', 0);
});

it('counts completed and failed backup runs per day', function (): void {
    $admin = statsAdmin();
    DB::table('activity_log')->delete();

    logActivity('2026-10-13 02:00:00', ['log_name' => 'backups', 'event' => AuditEvent::BackupCompleted->value]);
    logActivity('2026-10-14 02:00:00', ['log_name' => 'backups', 'event' => AuditEvent::BackupCompleted->value]);
    logActivity('2026-10-14 03:00:00', ['log_name' => 'backups', 'event' => AuditEvent::BackupFailed->value, 'severity' => 'critical']);
    logActivity('2026-10-14 03:30:00', ['log_name' => 'backups', 'event' => AuditEvent::BackupRequested->value]);

    $daily = actingAsSanctum($admin)
        ->getJson('/api/v1/admin/backups/history?days=7&tz=UTC')
        ->assertOk()
        ->json('data.daily');

    expect(pointFor($daily, '2026-10-13'))->toBe(['date' => '2026-10-13', 'completed' => 1, 'failed' => 0])
        ->and(pointFor($daily, '2026-10-14'))->toBe(['date' => '2026-10-14', 'completed' => 1, 'failed' => 1]);
});

it('reports the backup storage cap in bytes', function (): void {
    config(['backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than' => 5]);
    expect(app(BackupManager::class)->maxStorageBytes())->toBe(5 * 1024 * 1024);

    config(['backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than' => null]);
    expect(app(BackupManager::class)->maxStorageBytes())->toBeNull();
});

it('scopes profile activity stats to the caller and personal categories', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();
    DB::table('activity_log')->delete();

    $mine = ['causer_type' => $member->getMorphClass(), 'causer_id' => $member->id];

    logActivity('2026-10-14 08:00:00', $mine);
    logActivity('2026-10-14 09:00:00', [...$mine, 'log_name' => 'security', 'severity' => 'warning']);
    logActivity('2026-10-14 09:30:00', [...$mine, 'log_name' => 'media', 'event' => 'media.uploaded']);
    logActivity('2026-10-14 10:00:00', ['causer_type' => $other->getMorphClass(), 'causer_id' => $other->id]);

    $data = actingAsSanctum($member)
        ->getJson('/api/v1/profile/activity/stats?days=7&tz=UTC')
        ->assertOk()
        ->json('data');

    expect(pointFor($data['daily'], '2026-10-14'))->toBe([
        'date' => '2026-10-14',
        'auth' => 1,
        'security' => 1,
        'account' => 0,
        'users' => 0,
    ])->and($data['severity_totals'])->toBe(['info' => 1, 'notice' => 0, 'warning' => 1, 'critical' => 0]);
});
