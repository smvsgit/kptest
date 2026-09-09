<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$trustedProxySetting = trim((string) env('TRUSTED_PROXIES', '*'));

// Coolify gives each recreated application a new Docker network, so Traefik's
// container IP can change. "*" is safe for this deployment model because the
// app publishes no host port and is reachable publicly only through Traefik.
// A comma-separated proxy/CIDR allow-list can still be supplied if desired.
$trustedProxies = $trustedProxySetting === '*'
    ? '*'
    : array_values(array_filter(array_map('trim', explode(',', $trustedProxySetting))));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($trustedProxies): void {
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnforceUserSessionPolicy::class,
            \App\Http\Middleware\EnforcePortalMode::class,
            \App\Http\Middleware\EnsureTwoFactor::class,
        ]);
        $middleware->alias([
            'role'=>\App\Http\Middleware\EnsureRole::class,
            'permission'=>\App\Http\Middleware\EnsurePermission::class,
            '2fa'=>\App\Http\Middleware\EnsureTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
