<?php

namespace App\Policies;

use App\Models\User;
use Silber\Bouncer\Database\Role;

/**
 * Authorizes the Configuration → Roles screen. Roles are global definitions:
 * the mapping is viewable by every org member, but creating, editing and
 * deleting roles is reserved for super admins. Registered against Bouncer's
 * Role model in AppServiceProvider (there is no custom App\Models\Role).
 */
class RolePolicy
{
    /**
     * The read-only role → ability mapping is viewable by any org member.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Creating a role is a global concern reserved for super admins.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Editing a role's title and abilities is reserved for super admins.
     * Built-in roles stay editable — only their deletion is blocked.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Deleting a role is reserved for super admins, and built-in roles are
     * protected from deletion.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->isSuperAdmin() && ! $role->built_in;
    }
}
