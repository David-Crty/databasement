<?php

namespace App\Services\Roles;

use App\Support\BouncerScope;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

/**
 * Creates a custom (global) role and grants it the given abilities. Refreshes
 * Bouncer so the new role takes effect immediately.
 */
class CreateRoleAction
{
    /**
     * @param  list<string>  $abilities  ability names from the catalogue
     */
    public function execute(string $name, string $title, array $abilities): Role
    {
        // Role definitions and their ability grants are global (the active org
        // scope is preserved, so a mid-request admin keeps their context).
        BouncerScope::ensureFlags();

        // Create the role and grant its abilities atomically, so a failure can't
        // leave a half-created role behind.
        $role = DB::transaction(function () use ($name, $title, $abilities): Role {
            /** @var Role $role */
            $role = Bouncer::role()->create([
                'name' => $name,
                'title' => $title,
            ]);

            Bouncer::sync($role)->abilities($abilities);

            return $role;
        });

        Bouncer::refresh();

        return $role;
    }
}
