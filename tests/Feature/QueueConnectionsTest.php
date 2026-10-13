<?php

use App\Jobs\CreateBackupJob;
use App\Jobs\GenerateHlsJob;
use App\Jobs\RestoreBackupJob;

it('runs long jobs on connections whose retry_after outlives the job timeout', function (): void {
    config(['queue.long.media' => 'redis-media', 'queue.long.backups' => 'redis-backups']);

    $jobs = [
        new GenerateHlsJob(1),
        new CreateBackupJob('manual'),
        new RestoreBackupJob('backup.zip'),
    ];

    foreach ($jobs as $job) {
        $retryAfter = (int) config("queue.connections.{$job->connection}.retry_after");

        expect($job->connection)->toBeIn(['redis-media', 'redis-backups'])
            ->and($retryAfter)->toBeGreaterThan($job->timeout);
    }
});
