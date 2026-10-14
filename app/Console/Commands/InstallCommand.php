<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:install {--fresh : Drop all tables before migrating} {--force : Skip confirmation prompts and use env defaults}')]
#[Description('One-shot setup: generate the app key, run migrations, and create the first admin user')]
class InstallCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('Installing the application...');

        $this->ensureAppKey();
        $this->runMigrations();
        $this->createAdminUser();

        $this->components->info('Installation complete. You can now log in with the admin account you just created.');

        return self::SUCCESS;
    }

    private function ensureAppKey(): void
    {
        if (filled(config('app.key'))) {
            $this->components->task('Application key already set', fn () => true);

            return;
        }

        $this->components->task('Generating application key', function () {
            Artisan::call('key:generate', ['--force' => true]);

            return true;
        });
    }

    private function runMigrations(): void
    {
        $fresh = (bool) $this->option('fresh');

        $this->components->task($fresh ? 'Running fresh migrations' : 'Running migrations', function () use ($fresh) {
            Artisan::call($fresh ? 'migrate:fresh' : 'migrate', ['--force' => true]);

            return true;
        });
    }

    private function createAdminUser(): void
    {
        if (User::query()->where('role', UserRole::Admin)->exists() && ! $this->confirmReplaceExistingAdmin()) {
            $this->components->info('Skipping admin creation; an admin already exists.');

            return;
        }

        $force = (bool) $this->option('force');

        $name = $force ? (string) env('APP_INSTALL_ADMIN_NAME', 'Admin') : text(
            label: 'Admin name',
            default: 'Admin',
            required: true,
        );

        $email = $force ? (string) env('APP_INSTALL_ADMIN_EMAIL', 'admin@example.com') : text(
            label: 'Admin email',
            default: 'admin@example.com',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.',
        );

        $plainPassword = $force
            ? (string) (env('APP_INSTALL_ADMIN_PASSWORD') ?: str()->password(16))
            : password(
                label: 'Admin password',
                required: true,
                validate: fn (string $value) => mb_strlen($value) >= 8 ? null : 'Password must be at least 8 characters.',
            );

        // `role` is intentionally excluded from #[Fillable] on User (see that
        // class), so a plain updateOrCreate() would silently drop it.
        // forceFill() bypasses the guard for this trusted, server-only write.
        $admin = User::query()->firstOrNew(['email' => $email]);

        $admin->fill([
            'name' => $name,
            'password' => Hash::make($plainPassword),
            'email_verified_at' => now(),
        ]);

        $admin->forceFill(['role' => UserRole::Admin]);

        $admin->save();

        $this->components->info("Admin account ready: {$email}");

        if ($force && env('APP_INSTALL_ADMIN_PASSWORD') === null) {
            $this->components->warn("No APP_INSTALL_ADMIN_PASSWORD was set, so a random password was generated: {$plainPassword}");
            $this->components->warn('Store it now; it will not be shown again.');
        }
    }

    private function confirmReplaceExistingAdmin(): bool
    {
        if ((bool) $this->option('force')) {
            return false;
        }

        return confirm(
            label: 'An admin user already exists. Create/update another admin account?',
            default: false,
        );
    }
}
