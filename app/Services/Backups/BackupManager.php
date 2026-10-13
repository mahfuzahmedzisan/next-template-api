<?php

namespace App\Services\Backups;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Throwable;

/**
 * Lists the archives on the `backups` disk and tracks the running operation.
 *
 * Operation status and the lock live in the cache, never in the database: a
 * restore drops every table, including anything that would record its own
 * progress.
 */
class BackupManager
{
    public const DISK = 'backups';

    public const KIND_SCHEDULED = 'scheduled';

    public const KIND_MANUAL = 'manual';

    public const KIND_PRE_RESTORE = 'pre_restore';

    public const OPERATION_BACKUP = 'backup';

    public const OPERATION_RESTORE = 'restore';

    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    private const FILENAME_PATTERN = '/^[A-Za-z0-9._-]+\.zip$/';

    private const STATUS_KEY = 'backups:operation';

    private const LOCK_KEY = 'backups:lock';

    /** Longer than the job timeout so a crashed worker cannot hold it forever. */
    private const LOCK_SECONDS = 3900;

    /** A queued or running status older than this is treated as abandoned. */
    private const STALE_AFTER_MINUTES = 70;

    public function __construct(private readonly BackupRunner $runner) {}

    public function destination(): BackupDestination
    {
        return BackupDestination::create(self::DISK, (string) config('backup.backup.name'));
    }

    /**
     * Newest first.
     *
     * @return Collection<int, array{id: string, path: string, kind: string, size: int, created_at: string}>
     */
    public function list(): Collection
    {
        return collect($this->destination()->backups()->all())
            ->map(fn (Backup $backup): array => $this->describe($backup))
            ->sortByDesc('created_at')
            ->values();
    }

    public function find(string $id): ?Backup
    {
        if (preg_match(self::FILENAME_PATTERN, $id) !== 1) {
            return null;
        }

        return collect($this->destination()->backups()->all())
            ->first(fn (Backup $backup): bool => basename($backup->path()) === $id);
    }

    public function delete(Backup $backup): void
    {
        $backup->delete();
    }

    /**
     * @return array{id: string, path: string, kind: string, size: int, created_at: string}
     */
    public function describe(Backup $backup): array
    {
        $filename = basename($backup->path());

        return [
            'id' => $filename,
            'path' => $backup->path(),
            'kind' => $this->kindOf($filename),
            'size' => (int) $backup->sizeInBytes(),
            'created_at' => $this->createdAt($backup, $filename)->toIso8601String(),
        ];
    }

    public function filenameFor(string $kind): string
    {
        return str_replace('_', '-', $kind).'-'.now()->format('Y-m-d-H-i-s').'.zip';
    }

    public function kindOf(string $filename): string
    {
        return match (true) {
            str_starts_with($filename, 'manual-') => self::KIND_MANUAL,
            str_starts_with($filename, 'pre-restore-') => self::KIND_PRE_RESTORE,
            default => self::KIND_SCHEDULED,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $backups = $this->list();
        $backupBlocker = $this->backupBlocker();

        return [
            'count' => $backups->count(),
            'total_size' => (int) $backups->sum('size'),
            'newest_at' => $backups->first()['created_at'] ?? null,
            'retention_days' => (int) config('backup.schedule.retention_days', 14),
            'schedule' => [
                'time' => (string) config('backup.schedule.time'),
                'timezone' => (string) config('backup.schedule.timezone'),
                'next_run_at' => $this->nextRunAt()->toIso8601String(),
            ],
            'encrypted' => filled(config('backup.backup.password')),
            ...$this->health(),
            'backup_supported' => $backupBlocker === null,
            'backup_unsupported_reason' => $backupBlocker,
            'restore_supported' => $backupBlocker === null && $this->restoreBlocker() === null,
            'restore_unsupported_reason' => $backupBlocker ?? $this->restoreBlocker(),
        ];
    }

    public function nextRunAt(): Carbon
    {
        $timezone = (string) config('backup.schedule.timezone');
        [$hour, $minute] = array_map('intval', explode(':', (string) config('backup.schedule.time')) + [0, 0]);

        $next = Carbon::now($timezone)->setTime($hour, $minute);

        if ($next->isPast()) {
            $next->addDay();
        }

        return $next->utc();
    }

    public function backupBlocker(): ?string
    {
        $missing = $this->runner->missingBinaries();

        return $missing === []
            ? $this->queueBlocker()
            : 'Backups need '.implode(', ', $missing).' on the API server.';
    }

    /**
     * Restore drops every table. A database queue or cache would be wiped
     * while the job is still using it, and a sync queue would run the whole
     * restore inside the HTTP request.
     */
    public function restoreBlocker(): ?string
    {
        $store = (string) config('cache.default');
        $cacheDriver = (string) config("cache.stores.{$store}.driver");

        if (in_array($cacheDriver, ['database', 'array', 'null'], true)) {
            return "Restore needs a shared cache that lives outside the database (cache driver is {$cacheDriver}).";
        }

        $connection = (string) config('queue.default');
        $queueDriver = (string) config("queue.connections.{$connection}.driver");

        if ($queueDriver === 'database') {
            return 'Restore needs a queue that lives outside the database (queue driver is database).';
        }

        return $this->queueBlocker();
    }

    public function queueBlocker(): ?string
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");

        return in_array($driver, ['sync', 'null'], true)
            ? "Backups run on the queue, but the queue driver is {$driver}."
            : null;
    }

    public function lock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $status = Cache::get(self::STATUS_KEY);

        if (! is_array($status)) {
            return null;
        }

        if (in_array($status['state'] ?? null, [self::STATE_QUEUED, self::STATE_RUNNING], true)
            && Carbon::parse($status['updated_at'])->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            $status['state'] = self::STATE_FAILED;
            $status['message'] = 'The operation stopped without reporting back. Check the API logs.';
        }

        return $status;
    }

    public function isBusy(): bool
    {
        return in_array($this->status()['state'] ?? null, [self::STATE_QUEUED, self::STATE_RUNNING], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $operation, string $state, array $attributes = []): void
    {
        $this->withStatusLock(fn () => $this->write($operation, $state, $attributes));
    }

    /**
     * A run turned away by the lock must not overwrite the status of the run
     * that holds it, or the screen reports a live restore as failed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordRejected(string $operation, array $attributes): void
    {
        $this->withStatusLock(function () use ($operation, $attributes): void {
            $current = Cache::get(self::STATUS_KEY);

            if (is_array($current) && ($current['state'] ?? null) === self::STATE_RUNNING) {
                return;
            }

            $this->write($operation, self::STATE_FAILED, $attributes);
        });
    }

    /**
     * Checks for a running operation and records this one as queued in one
     * step, so two clicks can't both pass isBusy() and queue two jobs.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function claim(string $operation, array $attributes): bool
    {
        return (bool) $this->withStatusLock(function () use ($operation, $attributes): bool {
            if ($this->isBusy()) {
                return false;
            }

            $this->write($operation, self::STATE_QUEUED, $attributes);

            return true;
        });
    }

    private function withStatusLock(callable $callback): mixed
    {
        try {
            return Cache::lock('backups:status', 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(string $operation, string $state, array $attributes): void
    {
        $previous = Cache::get(self::STATUS_KEY);
        $sameRun = is_array($previous)
            && ($previous['operation'] ?? null) === $operation
            && in_array($previous['state'] ?? null, [self::STATE_QUEUED, self::STATE_RUNNING], true);
        $now = now()->toIso8601String();
        $finished = in_array($state, [self::STATE_COMPLETED, self::STATE_FAILED], true);

        $status = [
            'operation' => $operation,
            'kind' => null,
            'backup' => null,
            'safety_backup' => null,
            'message' => null,
            'user_id' => null,
            'started_at' => $now,
            ...($sameRun ? $previous : []),
            ...$attributes,
            'state' => $state,
            'updated_at' => $now,
            'finished_at' => $finished ? $now : null,
        ];

        Cache::put(self::STATUS_KEY, $status, now()->addDay());
    }

    public function forgetStatus(): void
    {
        Cache::forget(self::STATUS_KEY);
    }

    /**
     * @return array{healthy: bool, health_message: string|null}
     */
    private function health(): array
    {
        try {
            $status = BackupDestinationStatusFactory::createForMonitorConfig(app(Config::class)->monitoredBackups)
                ->first(fn ($status): bool => $status->backupDestination()->diskName() === self::DISK);
        } catch (Throwable $exception) {
            return ['healthy' => false, 'health_message' => $exception->getMessage()];
        }

        if ($status === null || $status->isHealthy()) {
            return ['healthy' => $status !== null, 'health_message' => null];
        }

        return [
            'healthy' => false,
            'health_message' => $status->getHealthCheckFailure()?->exception()->getMessage(),
        ];
    }

    private function createdAt(Backup $backup, string $filename): Carbon
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})\.zip$/', $filename, $matches) === 1) {
            $parsed = Carbon::createFromFormat('Y-m-d-H-i-s', $matches[1]);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return Carbon::instance($backup->date());
    }
}
