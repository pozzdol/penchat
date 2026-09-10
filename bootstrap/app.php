<?php

use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare Tunnel terminates TLS and cloudflared reaches nginx over
        // plain HTTP from loopback, so without this the framework reads the
        // request as insecure: redirects come back as http:// and the session
        // cookie loses its Secure flag. Only loopback can reach nginx, so
        // trusting it is exact rather than a wildcard.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias(['not-suspended' => EnsureNotSuspended::class]);

        // Without this, an unauthenticated web request gets a bare 401, not the
        // sign-in page.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
