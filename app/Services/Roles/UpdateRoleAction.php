<?php

namespace App\Services\Roles;

use App\Support\BouncerScope;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

/**
 * Updates a (global) role's title and syncs its granted abilities to exactly the
 * given set. Refreshes Bouncer so the change takes effect immediately, with no
 * redeploy.
 */
class UpdateRoleAction
{
    /**
     * @param  list<string>  $abilities  ability names from the catalogue
     */
    public function execute(Role $role, string $title, array $abilities): void
    {
        // Role definitions and their ability grants are global (the active org
        // scope is preserved, so a mid-request admin keeps their context).
        BouncerScope::ensureFlags();

        // Title change and ability sync are atomic.
        DB::transaction(function () use ($role, $title, $abilities): void {
            $role->update(['title' => $title]);
            Bouncer::sync($role)->abilities($abilities);
        });

        Bouncer::refresh();
    }
}
