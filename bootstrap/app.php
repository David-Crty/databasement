<?php

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Request;

if (file_exists(dirname(__DIR__).'/.env.local')) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__), '.env.local');
    $dotenv->load();
}

// Load extra env file if specified (useful for testing with different databases)
$extraEnvFile = getenv('EXTRA_ENV_FILE');
if ($extraEnvFile && file_exists(dirname(__DIR__).'/'.$extraEnvFile)) {
    $dotenv = Dotenv::createMutable(dirname(__DIR__), $extraEnvFile);
    $dotenv->load();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES'),
            headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_AWS_ELB |
            Request::HEADER_X_FORWARDED_TRAEFIK
        );
        $middleware->web(prepend: [
            \App\Http\Middleware\DemoModeMiddleware::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\SetLocale::class,
        ]);
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
