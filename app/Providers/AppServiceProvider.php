<?php

namespace App\Providers;

use App\Contracts\EncodesHls;
use App\Mail\ApplicationMailManager;
use App\Services\ApplicationSettings;
use App\Services\FfmpegHlsEncoder;
use App\Support\ClientContext;
use App\Support\FrontendOrigins;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EncodesHls::class, FfmpegHlsEncoder::class);
        $this->app->singleton(ApplicationSettings::class);
        $this->app->extend('mail.manager', function ($manager, $app) {
            return new ApplicationMailManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordResetUrl();
        $this->configureSeoMorphMap();
    }

    /**
     * Short, rename-proof aliases for seo_meta.seoable_type. Not enforced:
     * activity log, Sanctum and media rows keep their full class names.
     */
    private function configureSeoMorphMap(): void
    {
        $seoables = array_filter((array) config('seo.seoables', []), 'is_string');

        if ($seoables !== []) {
            Relation::morphMap($seoables);
        }
    }

    /**
     * `login` and `two-factor` limiters live in FortifyServiceProvider
     * alongside the Fortify actions they protect. `register` has no Fortify
     * equivalent, so it stays here.
     */
    private function configureRateLimiting(): void
    {
        // Requests come from the BFF, so $request->ip() would be one shared IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by((string) ClientContext::ip($request));
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(3)->by(ClientContext::ip($request).'|'.$request->input('email'));
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(5)->by((string) ClientContext::ip($request));
        });

        RateLimiter::for('admin-users', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: (string) ClientContext::ip($request));
        });

        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: (string) ClientContext::ip($request));
        });
    }

    /**
     * This is an API-only app with no `password.reset` web route, so the
     * default notification (which calls `route('password.reset', ...)`)
     * would 500. Point the emailed link at the Next.js BFF's reset-password
     * page instead; it posts the token straight to this API.
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $requested = FrontendOrigins::normalize((string) request()->header('X-Frontend-Origin', ''));
            $frontendUrl = ($requested !== null && FrontendOrigins::contains($requested))
                ? $requested
                : FrontendOrigins::default();

            return sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim($frontendUrl, '/'),
                $token,
                urlencode($notifiable->getEmailForPasswordReset()),
            );
        });
    }
}
