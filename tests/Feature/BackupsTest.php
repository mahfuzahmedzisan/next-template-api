<?php

use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Models\User;
use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupOperations;
use App\Services\Backups\BackupRunner;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('backups');

    // Restore refuses array caches and sync queues, so the tests use a file
    // cache in a scratch folder and a faked redis queue.
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => storage_path('framework/testing/backups-cache'),
        'cache.stores.file.lock_path' => storage_path('framework/testing/backups-cache'),
        'queue.default' => 'redis',
    ]);

    $this->runner = Mockery::mock(BackupRunner::class);
    $this->runner->shouldReceive('missingBinaries')->andReturn([])->byDefault();
    app()->instance(BackupRunner::class, $this->runner);
});

afterEach(function (): void {
    Cache::flush();
    File::deleteDirectory(storage_path('framework/testing/backups-cache'));
});

function putBackup(string $filename, ?int $modifiedAt = null): string
{
    $path = config('backup.backup.name').'/'.$filename;
    Storage::disk('backups')->put($path, 'PK fake archive');

    if ($modifiedAt !== null) {
        touch(Storage::disk('backups')->path($path), $modifiedAt);
    }

    return $path;
}

function backupLockIsFree(): bool
{
    $lock = app(BackupManager::class)->lock();

    return $lock->get() && $lock->release();
}

function backupAdmin(): User
{
    return User::factory()->admin()->create();
}

it('lists backups newest first with their kind and hides the disk path', function (): void {
    putBackup('scheduled-2026-10-09-18-00-00.zip');
    putBackup('manual-2026-10-10-08-30-00.zip');
    putBackup('pre-restore-2026-10-11-09-15-00.zip');
    Storage::disk('backups')->put(config('backup.backup.name').'/notes.txt', 'not a backup');

    $response = actingAsSanctum(backupAdmin())
        ->getJson('/api/v1/admin/backups')
        ->assertOk()
        ->assertJsonCount(3, 'data.backups')
        ->assertJsonPath('data.backups.0.id', 'pre-restore-2026-10-11-09-15-00.zip')
        ->assertJsonPath('data.backups.0.kind', 'pre_restore')
        ->assertJsonPath('data.backups.1.kind', 'manual')
        ->assertJsonPath('data.backups.2.kind', 'scheduled')
        ->assertJsonPath('data.summary.count', 3)
        ->assertJsonPath('data.summary.retention_days', 14)
        ->assertJsonPath('data.summary.schedule.time', '00:00')
        ->assertJsonPath('data.summary.schedule.timezone', 'Asia/Dhaka')
        ->assertJsonPath('data.summary.backup_supported', true)
        ->assertJsonPath('data.summary.restore_supported', true)
        ->assertJsonPath('data.status', null);

    expect($response->json('data.backups.0'))->not->toHaveKey('path');
});

it('refuses backups to a member', function (): void {
    $member = User::factory()->member()->create();

    actingAsSanctum($member)->getJson('/api/v1/admin/backups')->assertForbidden();
    actingAsSanctum($member)->postJson('/api/v1/admin/backups')->assertForbidden();
});

it('queues a manual backup and refuses a second one while it waits', function (): void {
    Queue::fake();
    $admin = backupAdmin();

    actingAsSanctum($admin)
        ->postJson('/api/v1/admin/backups')
        ->assertAccepted()
        ->assertJsonPath('data.status.operation', 'backup')
        ->assertJsonPath('data.status.state', 'queued')
        ->assertJsonPath('data.status.kind', 'manual');

    Queue::assertPushedOn('backups', CreateBackupJob::class, fn (CreateBackupJob $job): bool => $job->kind === 'manual' && $job->userId === $admin->id);

    actingAsSanctum($admin)
        ->postJson('/api/v1/admin/backups')
        ->assertStatus(409);

    Queue::assertPushed(CreateBackupJob::class, 1);
});

it('explains which binary is missing instead of queueing a backup', function (): void {
    Queue::fake();
    $this->runner->shouldReceive('missingBinaries')->andReturn(['mysqldump', 'gzip']);

    actingAsSanctum(backupAdmin())
        ->postJson('/api/v1/admin/backups')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Backups need mysqldump, gzip on the API server.');

    Queue::assertNothingPushed();
});

it('deletes a backup and rejects unknown or path-like names', function (): void {
    $path = putBackup('manual-2026-10-10-08-30-00.zip');
    $admin = backupAdmin();

    actingAsSanctum($admin)
        ->deleteJson('/api/v1/admin/backups/manual-2026-10-10-08-30-00.zip')
        ->assertOk()
        ->assertJsonCount(0, 'data.backups');

    Storage::disk('backups')->assertMissing($path);

    actingAsSanctum($admin)->deleteJson('/api/v1/admin/backups/missing-2026-01-01-00-00-00.zip')->assertNotFound();
    actingAsSanctum($admin)->deleteJson('/api/v1/admin/backups/..%2F..%2F.env.zip')->assertNotFound();
});

it('does not delete while another operation is running', function (): void {
    $path = putBackup('manual-2026-10-10-08-30-00.zip');
    app(BackupManager::class)->record(BackupManager::OPERATION_BACKUP, BackupManager::STATE_RUNNING);

    actingAsSanctum(backupAdmin())
        ->deleteJson('/api/v1/admin/backups/manual-2026-10-10-08-30-00.zip')
        ->assertStatus(409);

    Storage::disk('backups')->assertExists($path);
});

it('needs the password and the word RESTORE before queueing a restore', function (): void {
    Queue::fake();
    putBackup('scheduled-2026-10-09-18-00-00.zip');
    $admin = backupAdmin();
    $url = '/api/v1/admin/backups/scheduled-2026-10-09-18-00-00.zip/restore';

    actingAsSanctum($admin)
        ->postJson($url, ['current_password' => 'wrong', 'confirmation' => 'RESTORE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    actingAsSanctum($admin)
        ->postJson($url, ['current_password' => 'password', 'confirmation' => 'restore'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmation');

    Queue::assertNothingPushed();

    actingAsSanctum($admin)
        ->postJson($url, ['current_password' => 'password', 'confirmation' => 'RESTORE'])
        ->assertAccepted()
        ->assertJsonPath('data.status.operation', 'restore')
        ->assertJsonPath('data.status.backup', 'scheduled-2026-10-09-18-00-00.zip');

    Queue::assertPushedOn('backups', RestoreBackupJob::class, fn (RestoreBackupJob $job): bool => $job->backupId === 'scheduled-2026-10-09-18-00-00.zip');
});

it('refuses to restore when the queue lives in the database', function (): void {
    Queue::fake();
    putBackup('scheduled-2026-10-09-18-00-00.zip');
    config(['queue.default' => 'database']);

    actingAsSanctum(backupAdmin())
        ->postJson('/api/v1/admin/backups/scheduled-2026-10-09-18-00-00.zip/restore', [
            'current_password' => 'password',
            'confirmation' => 'RESTORE',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Restore needs a queue that lives outside the database (queue driver is database).');

    Queue::assertNothingPushed();
});

it('hands out a one-time download token for the same admin only', function (): void {
    putBackup('manual-2026-10-10-08-30-00.zip');
    $admin = backupAdmin();
    $url = '/api/v1/admin/backups/manual-2026-10-10-08-30-00.zip/download-token';

    actingAsSanctum($admin)
        ->postJson($url, ['current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $token = actingAsSanctum($admin)
        ->postJson($url, ['current_password' => 'password'])
        ->assertOk()
        ->json('data.token');

    actingAsSanctum(backupAdmin())
        ->get("/api/v1/admin/backups/download/{$token}")
        ->assertNotFound();

    $token = actingAsSanctum($admin)
        ->postJson($url, ['current_password' => 'password'])
        ->json('data.token');

    $download = actingAsSanctum($admin)->get("/api/v1/admin/backups/download/{$token}");
    $download->assertOk()->assertDownload('manual-2026-10-10-08-30-00.zip');
    expect($download->streamedContent())->toBe('PK fake archive');

    actingAsSanctum($admin)
        ->get("/api/v1/admin/backups/download/{$token}")
        ->assertNotFound();
});

it('schedules the backup at midnight Bangladesh time, then cleanup and monitoring', function (): void {
    Artisan::call('schedule:list');

    $events = collect(app(Schedule::class)->events());
    $find = fn (string $command): ?Event => $events->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

    expect($find('app:backup --kind=scheduled'))
        ->expression->toBe('0 0 * * *')
        ->timezone->toBe('Asia/Dhaka')
        ->and($find('backup:clean'))
        ->expression->toBe('30 0 * * *')
        ->and($find('backup:monitor'))
        ->expression->toBe('0 1 * * *');
});

it('creates a backup through the command and records it as completed', function (): void {
    $this->runner->shouldReceive('backup')->once()
        ->with(Mockery::pattern('/^scheduled-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.zip$/'))
        ->andReturnUsing(fn (string $filename) => putBackup($filename));

    $this->artisan('app:backup --kind=scheduled')->assertSuccessful();

    $status = app(BackupManager::class)->status();

    expect($status['state'])->toBe('completed')
        ->and($status['kind'])->toBe('scheduled')
        ->and(app(BackupManager::class)->list())->toHaveCount(1)
        ->and(backupLockIsFree())->toBeTrue();
});

it('records a failed backup and frees the lock', function (): void {
    $this->runner->shouldReceive('backup')->once()->andThrow(new RuntimeException('mysqldump: Access denied'));

    expect(fn () => app(BackupOperations::class)->backup('manual'))
        ->toThrow(RuntimeException::class, 'mysqldump: Access denied');

    expect(app(BackupManager::class)->status())
        ->state->toBe('failed')
        ->message->toBe('mysqldump: Access denied')
        ->and(backupLockIsFree())->toBeTrue();
});

it('takes a safety backup, restores, migrates and reindexes in that order', function (): void {
    $path = putBackup('scheduled-2026-10-09-18-00-00.zip');

    $this->runner->shouldReceive('backup')->once()->ordered()
        ->with(Mockery::pattern('/^pre-restore-.+\.zip$/'));
    $this->runner->shouldReceive('restore')->once()->ordered()->with($path);
    $this->runner->shouldReceive('migrate')->once()->ordered();
    $this->runner->shouldReceive('reindexSearch')->once()->ordered();

    (new RestoreBackupJob('scheduled-2026-10-09-18-00-00.zip', 7))->handle(app(BackupOperations::class));

    $status = app(BackupManager::class)->status();

    expect($status['operation'])->toBe('restore')
        ->and($status['state'])->toBe('completed')
        ->and($status['user_id'])->toBe(7)
        ->and($status['safety_backup'])->toStartWith('pre-restore-');
});

it('keeps the safety backup name when the restore itself fails', function (): void {
    putBackup('scheduled-2026-10-09-18-00-00.zip');

    $this->runner->shouldReceive('backup')->once();
    $this->runner->shouldReceive('restore')->once()->andThrow(new RuntimeException('ERROR 1045 at line 1'));
    $this->runner->shouldNotReceive('migrate');

    expect(fn () => app(BackupOperations::class)->restore('scheduled-2026-10-09-18-00-00.zip'))
        ->toThrow(RuntimeException::class);

    expect(app(BackupManager::class)->status())
        ->state->toBe('failed')
        ->message->toBe('ERROR 1045 at line 1')
        ->safety_backup->toStartWith('pre-restore-');
});

it('keeps fourteen days of backups when cleaning up', function (): void {
    $now = now()->getTimestamp();
    $fresh = putBackup('scheduled-a.zip', $now - 3600);
    $tenDays = putBackup('scheduled-b.zip', $now - 10 * 86400);
    $fifteenDays = putBackup('scheduled-c.zip', $now - 15 * 86400);
    $month = putBackup('manual-d.zip', $now - 30 * 86400);

    $this->artisan('backup:clean')->assertSuccessful();

    Storage::disk('backups')->assertExists([$fresh, $tenDays]);
    Storage::disk('backups')->assertMissing([$fifteenDays, $month]);
});
