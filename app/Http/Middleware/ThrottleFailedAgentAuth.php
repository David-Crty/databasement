<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleFailedAgentAuth
{
    /**
     * Throttle based on failed authentication attempts only.
     * Successful requests are never rate limited.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'agent-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'Too many failed attempts. Try again later.'], 429);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401 || $response->getStatusCode() === 403) {
            RateLimiter::hit($key, 300);
        } else {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
