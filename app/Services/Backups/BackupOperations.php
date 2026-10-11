<?php

namespace App\Services\Backups;

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
    ) {}

    /**
     * Returns the filename of the new archive.
     */
    public function backup(string $kind, ?int $userId = null): string
    {
        $lock = $this->backups->lock();

        if (! $lock->get()) {
            $this->backups->record(BackupManager::OPERATION_BACKUP, BackupManager::STATE_FAILED, [
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

            if ($this->backups->find($filename) === null) {
                throw new RuntimeException('The backup finished but no archive was written.');
            }

            $this->backups->record(BackupManager::OPERATION_BACKUP, BackupManager::STATE_COMPLETED, [
                'message' => 'Backup created.',
            ]);

            return $filename;
        } catch (Throwable $exception) {
            $this->fail(BackupManager::OPERATION_BACKUP, $exception);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function restore(string $id, ?int $userId = null): void
    {
        $lock = $this->backups->lock();

        if (! $lock->get()) {
            $this->backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_FAILED, [
                'backup' => $id,
                'message' => 'Another backup or restore is already running.',
            ]);

            throw new RuntimeException('Another backup or restore is already running.');
        }

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

            $this->runner->restore($backup->path());
            $this->runner->migrate();

            try {
                $this->runner->reindexSearch();
            } catch (Throwable $exception) {
                // The data is back; a stale search index is fixed by running scout:import-all.
                Log::warning('Search reindex after restore failed.', ['error' => $exception->getMessage()]);
            }

            $this->backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_COMPLETED, [
                'message' => 'Database restored.',
            ]);
        } catch (Throwable $exception) {
            $this->fail(BackupManager::OPERATION_RESTORE, $exception);

            throw $exception;
        } finally {
            $lock->release();
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
