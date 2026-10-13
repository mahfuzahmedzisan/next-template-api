<?php

use App\Http\Controllers\Api\V1\Admin\ActivityController;
use App\Http\Controllers\Api\V1\Admin\BackupController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\SeoAuditController;
use App\Http\Controllers\Api\V1\Admin\SeoController as AdminSeoController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\ApplicationSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BroadcastAuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MediaFileController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProfileActivityController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RealtimeController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\TwoFactorAuthenticationController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('settings/public', [ApplicationSettingsController::class, 'publicShow']);
Route::get('media/files/{media}', [MediaFileController::class, 'show'])
    ->whereNumber('media')
    ->middleware('throttle:120,1');

Route::get('projects', [ProjectController::class, 'index']);
Route::get('projects/{slug}', [ProjectController::class, 'show'])->where('slug', '[a-z0-9-]+');
Route::get('posts', [PostController::class, 'index']);
Route::get('posts/{slug}', [PostController::class, 'show'])->where('slug', '[a-z0-9-]+');

Route::get('seo/page', [SeoController::class, 'page']);
Route::get('seo/{file}', [SeoController::class, 'file'])
    ->where('file', 'sitemap\.xml|robots\.txt|llms\.txt|sitemaps/sitemap-\d+\.xml');

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
});

// Diagnostics for the admin Realtime screen; each call broadcasts an event.
Route::middleware(['auth:sanctum', 'role:admin', 'throttle:30,1'])->group(function (): void {
    Route::get('realtime/ping', [RealtimeController::class, 'ping']);
    Route::get('realtime/health', [RealtimeController::class, 'health']);
    Route::post('realtime/test-notification', [RealtimeController::class, 'testNotification']);
});

Route::middleware('auth:sanctum')->prefix('profile')->group(function (): void {
    Route::put('/', [ProfileController::class, 'update'])->middleware('throttle:sensitive');
    Route::put('password', [ProfileController::class, 'updatePassword'])->middleware('throttle:sensitive');
    Route::put('preferences', [ProfileController::class, 'updatePreferences']);
    Route::post('avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('avatar', [ProfileController::class, 'destroyAvatar']);
    Route::delete('sessions', [ProfileController::class, 'destroyOtherSessions'])->middleware('throttle:6,1');
    Route::delete('/', [ProfileController::class, 'destroy']);

    Route::get('activity', [ProfileActivityController::class, 'index']);

    Route::prefix('devices')->group(function (): void {
        Route::get('/', [DeviceController::class, 'index']);
        Route::get('history', [DeviceController::class, 'history']);

        Route::middleware('throttle:30,1')->group(function (): void {
            Route::delete('history', [DeviceController::class, 'clearHistory']);
            Route::delete('{device}', [DeviceController::class, 'revoke'])->whereNumber('device');
            Route::delete('{device}/history', [DeviceController::class, 'forget'])->whereNumber('device');
        });
    });

    Route::prefix('two-factor-authentication')->group(function (): void {
        Route::get('/', [TwoFactorAuthenticationController::class, 'show']);

        Route::middleware('throttle:sensitive')->group(function (): void {
            Route::post('/', [TwoFactorAuthenticationController::class, 'enable']);
            Route::post('confirm', [TwoFactorAuthenticationController::class, 'confirm']);
            Route::delete('/', [TwoFactorAuthenticationController::class, 'disable']);
            Route::post('recovery-codes', [TwoFactorAuthenticationController::class, 'recoveryCodes']);
        });
    });
});

/*
 * Admin routes stack `role:admin` on top of `auth:sanctum`. The ping route
 * stays so the authorization test can prove the middleware without a
 * resource. Video bytes never arrive here; the browser uploads them to tusd.
 */
Route::middleware(['auth:sanctum', 'role:admin', 'throttle:admin-users'])->group(function (): void {
    Route::get('admin/ping', function (Request $request) {
        return ApiResponse::success('Admin access confirmed.', [
            'user_id' => $request->user()->id,
        ]);
    });

    Route::get('admin/users', [UserController::class, 'index']);

    Route::get('admin/media', [MediaController::class, 'index']);
    Route::get('admin/media/slug-preview', [MediaController::class, 'slugPreview']);
    Route::post('admin/media', [MediaController::class, 'store']);
    Route::get('admin/media/{mediaItem}', [MediaController::class, 'show']);
    Route::patch('admin/media/{mediaItem}', [MediaController::class, 'update']);
    Route::delete('admin/media/{mediaItem}', [MediaController::class, 'destroy']);
    Route::post('admin/media/{mediaItem}/thumbnail', [MediaController::class, 'thumbnail']);
    Route::post('admin/media/{mediaItem}/retry', [MediaController::class, 'retry']);
    Route::post('admin/media/{mediaItem}/resume', [MediaController::class, 'resumeUpload']);

    Route::get('admin/settings', [ApplicationSettingsController::class, 'show']);
    Route::put('admin/settings/general', [ApplicationSettingsController::class, 'updateGeneral']);
    Route::put('admin/settings/smtp', [ApplicationSettingsController::class, 'updateSmtp']);
    Route::post('admin/settings/logo', [ApplicationSettingsController::class, 'uploadLogo']);
    Route::post('admin/settings/favicon', [ApplicationSettingsController::class, 'uploadFavicon']);

    Route::prefix('admin/seo')->group(function (): void {
        Route::get('/', [AdminSeoController::class, 'index']);
        Route::put('settings', [AdminSeoController::class, 'updateSettings']);
        Route::post('pages', [AdminSeoController::class, 'storePage']);
        Route::put('pages/{seoPage}', [AdminSeoController::class, 'updatePage'])->whereNumber('seoPage');
        Route::delete('pages/{seoPage}', [AdminSeoController::class, 'destroyPage'])->whereNumber('seoPage');
        Route::put('types/{type}', [AdminSeoController::class, 'updateType'])->where('type', '[a-z0-9_]+');
        Route::get('files/{file}/download', [AdminSeoController::class, 'download'])->where('file', 'sitemap|robots|llms');

        Route::get('audits', [SeoAuditController::class, 'index']);
        Route::get('audits/latest', [SeoAuditController::class, 'latest']);
        Route::get('audits/{audit}/pages/{page}', [SeoAuditController::class, 'page'])->whereNumber(['audit', 'page']);

        Route::middleware('throttle:20,1')->group(function (): void {
            Route::post('pages/{seoPage}/probe', [AdminSeoController::class, 'probePage'])->whereNumber('seoPage');
            Route::post('files/{file}/regenerate', [AdminSeoController::class, 'regenerate'])->where('file', 'sitemap|robots|llms|all');
            Route::post('audits', [SeoAuditController::class, 'store']);
        });
    });

    Route::prefix('admin/audit')->group(function (): void {
        Route::get('/', [ActivityController::class, 'index']);
        Route::get('overview', [ActivityController::class, 'overview']);
        Route::get('export', [ActivityController::class, 'export'])->middleware('throttle:5,1');
        Route::get('{activity}', [ActivityController::class, 'show'])->whereNumber('activity');
    });

    Route::prefix('admin/backups')->group(function (): void {
        Route::get('/', [BackupController::class, 'index']);
        Route::get('download/{token}', [BackupController::class, 'download'])->where('token', '[A-Za-z0-9]{64}');

        Route::middleware('throttle:10,1')->group(function (): void {
            Route::post('/', [BackupController::class, 'store']);
            Route::delete('{backup}', [BackupController::class, 'destroy'])->where('backup', '[A-Za-z0-9._-]+\.zip');
            Route::post('{backup}/restore', [BackupController::class, 'restore'])->where('backup', '[A-Za-z0-9._-]+\.zip');
            Route::post('{backup}/download-token', [BackupController::class, 'downloadToken'])->where('backup', '[A-Za-z0-9._-]+\.zip');
        });
    });
});
