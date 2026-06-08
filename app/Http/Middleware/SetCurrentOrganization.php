<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $this->currentOrganization->reset();

        if ($request->is('api/*') || $request->is('mcp*')) {
            $this->resolveApiOrganization($request, $user);
        } else {
            /** @var string|null $cookieOrgId */
            $cookieOrgId = $request->cookie(CurrentOrganization::COOKIE_NAME);
            $this->currentOrganization->resolveForUser($user, $cookieOrgId);
        }

        if (! $this->currentOrganization->isResolved()) {
            if ($request->expectsJson()) {
                abort(401, __('Your account is not a member of any organization. Please contact an administrator.'));
            }

            Auth::guard('web')->logout();
            Session::invalidate();
            Session::regenerateToken();
            session()->flash('error', __('Your account is not a member of any organization. Please contact an administrator.'));

            return redirect()->route('login');
        }

        return $next($request);
    }

    /**
     * Resolve organization for API requests.
     * Aborts with 403 when the client explicitly requests an org that is invalid or inaccessible.
     */
    private function resolveApiOrganization(Request $request, User $user): void
    {
        /** @var string|null $orgId */
        $orgId = $request->query('org_id') ?? $request->header('X-Organization-Id');

        $this->currentOrganization->resolveForUser($user, $orgId);

        if ($orgId && (! $this->currentOrganization->isResolved() || $this->currentOrganization->id() !== $orgId)) {
            abort(403, 'The requested organization is not accessible.');
        }
    }
}
