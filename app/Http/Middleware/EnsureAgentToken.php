<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // @phpstan-ignore instanceof.alwaysFalse (Sanctum polymorphic tokens resolve Agent at runtime)
        if (! $request->user() instanceof Agent) {
            return response()->json(['message' => 'Unauthorized. Agent token required.'], 403);
        }

        return $next($request); // @phpstan-ignore deadCode.unreachable
    }
}
