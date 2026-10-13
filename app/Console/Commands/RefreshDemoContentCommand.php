<?php

namespace App\Console\Commands;

use App\Events\SeoInvalidated;
use Database\Seeders\PostSeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\SeoPageSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class RefreshDemoContentCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'demo:refresh-content {--force : Run without asking in production}';

    protected $description = 'Rewrite the seeded demo posts and projects to the current seeder copy; records created by admins are left alone';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Page SEO stays fill-only: superseded seeder copy is replaced, admin edits are kept.
        $seeders = [
            new SeoPageSeeder,
            (new ProjectSeeder)->overwriting(),
            (new PostSeeder)->overwriting(),
        ];

        foreach ($seeders as $seeder) {
            $this->components->task($seeder::class, fn () => $seeder->setContainer($this->laravel)->setCommand($this)->__invoke());
        }

        SeoInvalidated::dispatch();

        $this->components->info('Demo content refreshed.');

        return self::SUCCESS;
    }
}
