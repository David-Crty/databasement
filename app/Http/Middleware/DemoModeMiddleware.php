<?php

namespace App\Http\Middleware;

use App\Enums\Ability;
use App\Models\Organization;
use App\Models\User;
use App\Services\Roles\AssignRoleToUserAction;
use App\Services\Roles\CreateRoleAction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Silber\Bouncer\Database\Role;
use Symfony\Component\HttpFoundation\Response;

class DemoModeMiddleware
{
    public const DEMO_ROLE = 'demo';

    /**
     * Routes that demo users are not allowed to access.
     *
     * @var array<string>
     */
    protected array $restrictedRoutesForDemo = [
        'profile.edit',
        'user-password.edit',
        'two-factor.show',
    ];

    /**
     * Handle an incoming request.
     * In demo mode, ensure demo user exists and restrict demo users from certain routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.demo_mode')) {
            return $next($request);
        }

        // Ensure demo user exists when visiting login page
        if ($request->route()?->getName() === 'login') {
            $this->ensureDemoUserExists();
        }

        // Block demo users from restricted routes
        if (Auth::check() && Auth::user()->isDemo()) {
            if (in_array($request->route()?->getName(), $this->restrictedRoutesForDemo)) {
                abort(403);
            }
        }

        return $next($request);
    }

    /**
     * Create the demo user and the demo role if they don't exist, and attach
     * the user to the main org with that role. Read-only restrictions are still
     * enforced via isDemo().
     */
    protected function ensureDemoUserExists(): void
    {
        if (! Role::query()->where('name', self::DEMO_ROLE)->exists()) {
            app(CreateRoleAction::class)->execute(self::DEMO_ROLE, 'Demo', [
                Ability::OperateRestores->value,
                Ability::DeleteSnapshots->value,
                Ability::DownloadSnapshots->value,
            ]);
        }

        $user = User::firstOrCreate(
            ['email' => User::DEMO_EMAIL],
            [
                'name' => 'Demo User',
                'password' => bcrypt(config('app.demo_user_password')),
                'invitation_accepted_at' => now(),
            ]
        );

        $org = Organization::default();
        $user->organizations()->syncWithoutDetaching([$org->id]);
        app(AssignRoleToUserAction::class)->execute($user, self::DEMO_ROLE, $org);
    }
}
