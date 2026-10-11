<?php

use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Models\UserDevice;
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

    MediaItem::query()
        ->where('status', VideoStatus::AwaitingUpload)
        ->where('created_at', '<', now()->subHours($hours))
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
