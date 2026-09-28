<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json(['message' => 'Unauthorized. User token required.'], 403);
        }

        return $next($request);
    }
}
