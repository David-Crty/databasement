<?php

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->web(prepend: [
            \App\Http\Middleware\DemoModeMiddleware::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
