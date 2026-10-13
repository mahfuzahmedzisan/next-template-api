<?php

namespace App\Services\Backups;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Audit\RestorePreserver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Creates and restores backups while holding the shared lock, so the nightly
 * run, "Backup now" and a restore never overlap.
 */
class BackupOperations
{
    public function __construct(
        private readonly BackupManager $backups,
        private readonly BackupRunner $runner,
        private readonly RestorePreserver $auditTrail,
    ) {}

    /**
     * Returns the filename of the new archive.
     */
    public function backup(string $kind, ?int $userId = null): string
    {
        $lock = $this->backups->lock();

        if (! $lock->get()) {
            $this->backups->recordRejected(BackupManager::OPERATION_BACKUP, [
                'kind' => $kind,
                'message' => 'Another backup or restore is already running.',
            ]);

            throw new RuntimeException('Another backup or restore is already running.');
        }

        $filename = $this->backups->filenameFor($kind);

        try {
            $this->backups->record(BackupManager::OPERATION_BACKUP, BackupManager::STATE_RUNNING, [
                'kind' => $kind,
                'backup' => $filename,
                'user_id' => $userId,
            ]);

            $this->runner->backup($filename);

            $created = $this->backups->find($filename)
                ?? throw new RuntimeException('The backup finished but no archive was written.');

            $this->backups->record(BackupManager::OPERATION_BACKUP, BackupManager::STATE_COMPLETED, [
                'message' => 'Backup created.',
            ]);

            Audit::log(AuditEvent::BackupCompleted, causer: $userId, properties: [
                'kind' => $kind,
                'size' => $this->backups->describe($created)['size'],
            ], subjectLabel: $filename);

            return $filename;
        } catch (Throwable $exception) {
            $this->fail(BackupManager::OPERATION_BACKUP, $exception);

            Audit::log(AuditEvent::BackupFailed, causer: $userId, properties: [
                'kind' => $kind,
                'reason' => mb_substr($exception->getMessage(), 0, 500),
            ], subjectLabel: $filename);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function restore(string $id, ?int $userId = null): void
    {
        $lock = $this->backups->lock();

        if (! $lock->get()) {
            $this->backups->recordRejected(BackupManager::OPERATION_RESTORE, [
                'backup' => $id,
                'message' => 'Another backup or restore is already running.',
            ]);

            throw new RuntimeException('Another backup or restore is already running.');
        }

        $snapshot = null;
        $replayed = false;

        try {
            $backup = $this->backups->find($id)
                ?? throw new RuntimeException('That backup no longer exists.');

            $this->backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_RUNNING, [
                'backup' => $id,
                'user_id' => $userId,
                'message' => 'Taking a safety backup of the current database.',
            ]);

            $safety = $this->backups->filenameFor(BackupManager::KIND_PRE_RESTORE);
            $this->runner->backup($safety);

            $this->backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_RUNNING, [
                'safety_backup' => $safety,
                'message' => 'Restoring the database.',
            ]);

            $snapshot = $this->auditTrail->capture(Carbon::parse($this->backups->describe($backup)['created_at']));

            $this->runner->restore($backup->path());
            $this->runner->migrate();

            $kept = $this->auditTrail->replay($snapshot);
            $replayed = true;

            try {
                $this->runner->reindexSearch();
            } catch (Throwable $exception) {
                // The data is back; a stale search index is fixed by running scout:import-all.
                Log::warning('Search reindex after restore failed.', ['error' => $exception->getMessage()]);
            }

            $this->backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_COMPLETED, [
                'message' => 'Database restored.',
            ]);

            Audit::log(AuditEvent::RestoreCompleted, causer: $userId, properties: [
                'safety_backup' => $safety,
                'audit_entries_kept' => $kept,
            ], subjectLabel: $id);
        } catch (Throwable $exception) {
            $this->fail(BackupManager::OPERATION_RESTORE, $exception);

            if (! $replayed) {
                $this->replayAfterFailure($snapshot);
            }

            Audit::log(AuditEvent::RestoreFailed, causer: $userId, properties: [
                'reason' => mb_substr($exception->getMessage(), 0, 500),
            ], subjectLabel: $id);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * A restore that fails part way may already have dropped the audit table.
     * Putting the captured rows back is best effort; the snapshot stays on
     * disk if even that fails.
     */
    private function replayAfterFailure(?string $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        try {
            $this->runner->migrate();
            $this->auditTrail->replay($snapshot);
        } catch (Throwable $exception) {
            Log::error('Could not put the audit log back after a failed restore.', [
                'snapshot' => $snapshot,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function fail(string $operation, Throwable $exception): void
    {
        Log::error("Backup {$operation} failed.", ['error' => $exception->getMessage()]);

        $this->backups->record($operation, BackupManager::STATE_FAILED, [
            'message' => $exception->getMessage(),
        ]);
    }
}
