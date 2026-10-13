<?php

use App\Enums\VideoStatus;
use App\Jobs\Seo\ProbeSeoPath;
use App\Jobs\Seo\RegenerateSeoFiles;
use App\Models\MediaItem;
use App\Models\UserDevice;
use App\Services\Seo\Audit\SeoAuditRunner;
use App\Services\StoreFinishedUpload;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $uploads = app(StoreFinishedUpload::class);

    MediaItem::query()
        ->where('status', VideoStatus::AwaitingUpload)
        ->where('created_at', '>', now()->subDay())
        ->orderBy('id')
        ->limit(20)
        ->each(function (MediaItem $item) use ($uploads): void {
            $uploads->adoptIfComplete($item);
        });
})->everyMinute()->name('adopt-finished-tus-uploads')->withoutOverlapping();

Schedule::call(function (): void {
    $hours = max(1, (int) config('media-hls.abandon_after_hours'));

    // lazyById pages by key, so deleting rows mid-loop never skips the next page.
    MediaItem::query()
        ->where('status', VideoStatus::AwaitingUpload)
        ->where('created_at', '<', now()->subHours($hours))
        ->lazyById(100)
        ->each(fn (MediaItem $item) => $item->delete());
})->hourly()->name('prune-abandoned-media-uploads')->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('model:prune', ['--model' => [UserDevice::class]])->daily();

// Retention comes from Application settings > General (30 to 730 days).
Schedule::command('app:audit-prune')
    ->dailyAt((string) config('audit.prune.time'))
    ->timezone((string) config('audit.prune.timezone'))
    ->withoutOverlapping(60);

// Database backups. Avoid 02:00-03:00 in zones with daylight saving time.
$backupTimezone = (string) config('backup.schedule.timezone');

Schedule::command('app:backup --kind=scheduled')
    ->dailyAt((string) config('backup.schedule.time'))
    ->timezone($backupTimezone)
    ->withoutOverlapping(120);

Schedule::command('backup:clean')
    ->dailyAt('00:30')
    ->timezone($backupTimezone)
    ->withoutOverlapping(60);

Schedule::command('backup:monitor')
    ->dailyAt('01:00')
    ->timezone($backupTimezone);

// SEO: re-check every registered path, then rebuild the files from the result.
Schedule::call(fn () => ProbeSeoPath::dispatchForAll())
    ->dailyAt('03:30')
    ->name('seo-probe-paths')
    ->withoutOverlapping(30);

Schedule::job(new RegenerateSeoFiles)
    ->dailyAt('04:00')
    ->name('seo-regenerate-files');

// Weekly SEO health scan; the runner keeps only the latest audits.
Schedule::call(fn () => app(SeoAuditRunner::class)->start(null, trigger: 'schedule'))
    ->weeklyOn(1, '04:30')
    ->name('seo-audit-weekly')
    ->withoutOverlapping(30);
