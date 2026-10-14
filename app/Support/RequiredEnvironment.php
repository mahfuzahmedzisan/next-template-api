<?php

namespace App\Support;

use RuntimeException;

/**
 * Fail-fast check for the handful of env vars this app cannot run without.
 * Runs once at boot (see AppServiceProvider::boot()) so a missing value
 * throws a clear, aggregated error immediately instead of surfacing as a
 * confusing failure deep inside a request (e.g. EnsureTrustedBff rejecting
 * every request because BFF_SECRET is empty).
 *
 * Only genuinely required-for-the-app-to-function vars belong here.
 * Everything optional (mail, search, media, backups) is read directly
 * where it's used and defaults safely — see config/modules.php for the
 * optional feature toggles.
 */
class RequiredEnvironment
{
    /**
     * @return list<string>
     */
    public static function missing(): array
    {
        $problems = [];

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY is not set. Run: php artisan key:generate (or php artisan app:install).';
        }

        if (blank(config('services.frontend.bff_secret'))) {
            $problems[] = 'BFF_SECRET is not set. Generate with: openssl rand -base64 32 — must match the Next.js app.';
        }

        if (FrontendOrigins::all() === []) {
            $problems[] = 'FRONTEND_URLS (or FRONTEND_URL) is not set. At least one trusted frontend origin is required.';
        }

        return $problems;
    }

    public static function assertValid(): void
    {
        $problems = self::missing();

        if ($problems === []) {
            return;
        }

        $list = implode("\n", array_map(fn (string $problem) => "  - {$problem}", $problems));

        throw new RuntimeException(
            "Invalid environment configuration. Fix the following and restart:\n{$list}\n\nSee .env.example for the full list of variables.",
        );
    }
}
