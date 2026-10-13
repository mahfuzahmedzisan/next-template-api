<?php

use App\Services\Backups\BackupManager;

it('keeps a running restore visible when a second run is turned away', function (): void {
    $backups = app(BackupManager::class);

    $backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_RUNNING, ['backup' => 'manual-a.zip']);
    $backups->recordRejected(BackupManager::OPERATION_BACKUP, [
        'kind' => BackupManager::KIND_MANUAL,
        'message' => 'Another backup or restore is already running.',
    ]);

    expect($backups->status())
        ->toMatchArray([
            'operation' => BackupManager::OPERATION_RESTORE,
            'state' => BackupManager::STATE_RUNNING,
            'backup' => 'manual-a.zip',
        ]);
});

it('records the rejection when nothing else is running', function (): void {
    $backups = app(BackupManager::class);
    $backups->forgetStatus();

    $backups->recordRejected(BackupManager::OPERATION_BACKUP, ['message' => 'Another backup or restore is already running.']);

    expect($backups->status()['state'] ?? null)->toBe(BackupManager::STATE_FAILED);
});
