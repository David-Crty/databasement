<?php

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Request;

if (! defined('TRUSTED_PROXIES_DEFAULT')) {
    define('TRUSTED_PROXIES_DEFAULT', '127.0.0.0/8,10.0.0.0/8,100.64.0.0/10,169.254.0.0/16,172.16.0.0/12,192.168.0.0/16');
}

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
            at: env('TRUSTED_PROXIES', TRUSTED_PROXIES_DEFAULT),
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
        $middleware->alias([
            'agent' => \App\Http\Middleware\EnsureAgentToken::class,
            'throttle-failed-agent-auth' => \App\Http\Middleware\ThrottleFailedAgentAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (\Illuminate\Contracts\Encryption\DecryptException $e, $request) {
            if ($request->is('two-factor-challenge')) {
                return redirect()->route('login')->withErrors([
                    'email' => __('Your session or encryption key has changed. Please log in again. If this persists, your APP_KEY may have changed since two-factor authentication was set up.'),
                ]);
            }
        });
    })->create();
