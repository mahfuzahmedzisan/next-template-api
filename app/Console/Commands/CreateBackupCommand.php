<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupOperations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:backup {--kind=scheduled : scheduled or manual}')]
#[Description('Back up the database to the backups disk, sharing the lock with the admin screen')]
class CreateBackupCommand extends Command
{
    public function handle(BackupOperations $operations): int
    {
        $kind = (string) $this->option('kind');

        if (! in_array($kind, [BackupManager::KIND_SCHEDULED, BackupManager::KIND_MANUAL], true)) {
            $this->components->error('The kind must be scheduled or manual.');

            return self::INVALID;
        }

        try {
            $filename = $operations->backup($kind);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Backup written: {$filename}");

        return self::SUCCESS;
    }
}
