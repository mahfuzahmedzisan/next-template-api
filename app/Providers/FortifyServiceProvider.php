<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * This app is a stateless JSON API consumed by a Next.js BFF - Fortify's
     * own session/CSRF-based routes and Blade views don't apply here. We keep
     * Fortify purely as an "actions" library (CreateNewUser, UpdateUserPassword,
     * the two-factor Actions, etc.) wired directly into our own Api\V1
     * controllers, and turn off everything route/view related.
     *
     * `ignoreRoutes()` must run here (register phase), before the package's
     * own provider boots and decides whether to register its routes.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // 5 attempts/min per email+IP - mirrors Fortify's own default, applied
        // via the `throttle:login` middleware on POST /auth/login.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)
                ->by($throttleKey)
                ->response(function (Request $request, array $headers) use ($throttleKey) {
                    // One entry per key per minute, however hard the endpoint is hammered.
                    if (Cache::add('audit:login-throttled:'.sha1($throttleKey), true, 60)) {
                        $email = Str::lower((string) $request->input(Fortify::username()));

                        Audit::log(
                            AuditEvent::LoginThrottled,
                            subject: User::query()->where('email', $email)->first(),
                            properties: ['email' => $email],
                            subjectLabel: $email !== '' ? $email : null,
                        );
                    }

                    return response()->json(['message' => 'Too Many Attempts.'], 429, $headers);
                });
        });

        // Our two-factor challenge is stateless (no PHP session - see
        // AuthController::twoFactorChallenge()), so this is keyed by the
        // challenge token + IP rather than Fortify's default session-based
        // `login.id`, which does not exist in this API.
        RateLimiter::for('two-factor', function (Request $request) {
            $key = (string) $request->input('challenge_token', $request->ip());

            return Limit::perMinute(5)->by($key.'|'.$request->ip());
        });
    }
}
