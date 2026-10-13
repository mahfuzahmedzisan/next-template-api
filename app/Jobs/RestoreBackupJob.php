<?php

namespace App\Jobs;

use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupOperations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

#[FailOnTimeout]
class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $backupId, public ?int $userId = null)
    {
        $this->onConnection((string) config('queue.long.backups'));
        $this->onQueue('backups');
    }

    public function handle(BackupOperations $operations): void
    {
        $operations->restore($this->backupId, $this->userId);
    }

    public function failed(?Throwable $exception): void
    {
        $backups = app(BackupManager::class);

        if ($backups->isBusy()) {
            $backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_FAILED, [
                'message' => $exception?->getMessage() ?: 'The restore job failed.',
            ]);
        }
    }
}
