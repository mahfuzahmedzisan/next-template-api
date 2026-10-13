<?php

use App\Http\Middleware\CaptureAuditContext;
use App\Http\Middleware\EnsureTrustedBff;
use App\Http\Middleware\EnsureTusHook;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/internal.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'bff' => EnsureTrustedBff::class,
            'tus.hook' => EnsureTusHook::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        // Every /api/v1 route. Not applied to /up (Coolify healthcheck).
        $middleware->appendToGroup('api', EnsureTrustedBff::class);
        $middleware->appendToGroup('api', CaptureAuditContext::class);

        // This is an API-only application with no `login` route to redirect
        // guests to. Without this, Laravel's default Authenticate middleware
        // throws a RouteNotFoundException (surfaced as a 500) for any
        // unauthenticated request that doesn't explicitly send
        // `Accept: application/json`, instead of a clean 401 JSON response.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
