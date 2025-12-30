<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DemoModeMiddleware
{
    /**
     * Routes that should not trigger auto-login.
     * These are auth-related routes where guests need to remain guests.
     *
     * @var array<string>
     */
    protected array $excludedRoutes = [
        'login',
        'login.store',
        'register',
        'register.store',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'two-factor.login',
        'two-factor.login.store',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'invitation.accept',
        'demo.logout',
    ];

    /**
     * Routes that demo users are not allowed to access.
     *
     * @var array<string>
     */
    protected array $restrictedRoutesForDemo = [
        'profile.edit',
        'user-password.edit',
        'two-factor.show',
        'api-tokens.index',
    ];

    /**
     * Handle an incoming request.
     * If demo mode is enabled and user is guest, auto-login as demo user.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.demo_mode')) {
            return $next($request);
        }

        // If user is already logged in, don't replace them
        if (Auth::check()) {
            // Block demo users from restricted routes
            if (Auth::user()->isDemo()) {
                if (in_array($request->route()?->getName(), $this->restrictedRoutesForDemo)) {
                    abort(403);
                }
            }

            return $next($request);
        }

        // Skip auto-login for auth-related routes (let guests access login/register)
        if (in_array($request->route()?->getName(), $this->excludedRoutes)) {
            return $next($request);
        }

        // Skip auto-login if no users exist (allow first admin to register)
        if (User::count() === 0) {
            return $next($request);
        }

        // Auto-login guests as demo user
        $demoUser = $this->getOrCreateDemoUser();
        Auth::login($demoUser);

        return $next($request);
    }

    private function getOrCreateDemoUser(): User
    {
        $email = config('app.demo_user_email');

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Demo User',
                'email' => $email,
                'password' => bcrypt(str()->random(32)),
                'role' => User::ROLE_DEMO,
                'invitation_accepted_at' => now(),
            ]
        );
    }
}
