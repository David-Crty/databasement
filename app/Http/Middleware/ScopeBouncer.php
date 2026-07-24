<?php

namespace App\Http\Middleware;

use App\Services\CurrentOrganization;
use App\Support\BouncerScope;
use Closure;
use Illuminate\Http\Request;

/**
 * Scopes Bouncer to the current organization for the request.
 *
 * Role and ability definitions are global; only role assignments are scoped per
 * organization. So a user can be an Admin in one organization and a Viewer in
 * another, while the roles themselves are shared (see {@see BouncerScope}).
 */
class ScopeBouncer
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Agent mode never authorizes UI requests and has no database.
        if (config('agent.enabled')) {
            return $next($request);
        }

        $organization = app(CurrentOrganization::class);

        // Set the scope explicitly every request (worker runtimes reuse the
        // container). Guests / unresolved contexts fall back to the global
        // scope, where no per-org assignment resolves.
        BouncerScope::apply(
            $organization->isResolved() ? $organization->id() : null
        );

        return $next($request);
    }
}
