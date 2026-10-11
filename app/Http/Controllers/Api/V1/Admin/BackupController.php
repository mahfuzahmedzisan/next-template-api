<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Services\Backups\BackupManager;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Database backups on the `backups` disk. Creating and restoring are queued
 * on the `backups` queue; the status the screen polls comes from the cache.
 */
class BackupController extends Controller
{
    private const DOWNLOAD_TOKEN_SECONDS = 300;

    public function __construct(private readonly BackupManager $backups) {}

    public function index(): JsonResponse
    {
        return $this->apiSuccess('Backups retrieved successfully.', $this->payload());
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->backups->isBusy()) {
            return $this->busy();
        }

        if (($blocker = $this->backups->backupBlocker()) !== null) {
            return $this->apiUnprocessable($blocker);
        }

        $this->backups->record(BackupManager::OPERATION_BACKUP, BackupManager::STATE_QUEUED, [
            'kind' => BackupManager::KIND_MANUAL,
            'user_id' => $request->user()->id,
            'message' => 'Waiting for the backup worker.',
        ]);

        CreateBackupJob::dispatch(BackupManager::KIND_MANUAL, $request->user()->id);

        Audit::log(AuditEvent::BackupRequested, properties: ['kind' => BackupManager::KIND_MANUAL]);

        return $this->apiSuccess('Backup started.', $this->payload(), Response::HTTP_ACCEPTED);
    }

    public function destroy(string $backup): JsonResponse
    {
        if ($this->backups->isBusy()) {
            return $this->busy();
        }

        $model = $this->backups->find($backup);

        if ($model === null) {
            return ApiResponse::notFound('Backup not found.');
        }

        $details = $this->backups->describe($model);
        $this->backups->delete($model);

        Audit::log(AuditEvent::BackupDeleted, properties: [
            'kind' => $details['kind'],
            'size' => $details['size'],
            'taken_at' => $details['created_at'],
        ], subjectLabel: $backup);

        return $this->apiSuccess('Backup deleted.', $this->payload());
    }

    public function restore(Request $request, string $backup): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ], [
            'confirmation.in' => 'Type RESTORE to confirm.',
        ]);

        if ($this->backups->isBusy()) {
            return $this->busy();
        }

        if ($this->backups->find($backup) === null) {
            return ApiResponse::notFound('Backup not found.');
        }

        if (($blocker = $this->backups->backupBlocker() ?? $this->backups->restoreBlocker()) !== null) {
            return $this->apiUnprocessable($blocker);
        }

        $this->backups->record(BackupManager::OPERATION_RESTORE, BackupManager::STATE_QUEUED, [
            'backup' => $backup,
            'user_id' => $request->user()->id,
            'message' => 'Waiting for the backup worker.',
        ]);

        // Written before the job runs, so the restore preserves this entry as well.
        Audit::log(AuditEvent::RestoreRequested, subjectLabel: $backup);

        RestoreBackupJob::dispatch($backup, $request->user()->id);

        return $this->apiSuccess('Restore started.', $this->payload(), Response::HTTP_ACCEPTED);
    }

    public function downloadToken(Request $request, string $backup): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        if ($this->backups->find($backup) === null) {
            return ApiResponse::notFound('Backup not found.');
        }

        $token = Str::random(64);

        Cache::put($this->downloadKey($token), [
            'backup' => $backup,
            'user_id' => $request->user()->id,
        ], self::DOWNLOAD_TOKEN_SECONDS);

        Audit::log(AuditEvent::BackupDownloadRequested, subjectLabel: $backup);

        return $this->apiSuccess('Download ready.', [
            'token' => $token,
            'expires_at' => now()->addSeconds(self::DOWNLOAD_TOKEN_SECONDS)->toIso8601String(),
        ]);
    }

    public function download(Request $request, string $token): StreamedResponse|JsonResponse
    {
        $grant = Cache::pull($this->downloadKey($token));

        if (! is_array($grant) || (int) $grant['user_id'] !== (int) $request->user()->id) {
            return ApiResponse::notFound('This download link has expired.');
        }

        $model = $this->backups->find((string) $grant['backup']);

        if ($model === null) {
            return ApiResponse::notFound('Backup not found.');
        }

        Audit::log(AuditEvent::BackupDownloaded, properties: [
            'size' => $this->backups->describe($model)['size'],
        ], subjectLabel: basename($model->path()));

        return Storage::disk(BackupManager::DISK)->download($model->path(), basename($model->path()), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'backups' => BackupResource::collection($this->backups->list())->resolve(),
            'summary' => $this->backups->summary(),
            'status' => $this->backups->status(),
        ];
    }

    private function busy(): JsonResponse
    {
        return ApiResponse::error('A backup or restore is already running.', Response::HTTP_CONFLICT, [
            'status' => $this->backups->status(),
        ]);
    }

    private function downloadKey(string $token): string
    {
        return 'backups:download:'.hash('sha256', $token);
    }
}
