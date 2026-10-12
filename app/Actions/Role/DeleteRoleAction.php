<?php

namespace App\Actions\Role;

use App\Exceptions\Role\RoleInUseException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Models;
use Silber\Bouncer\Database\Role;

/**
 * Deletes a custom role nobody holds anymore, with its ability grants (via
 * the database foreign keys). A role still assigned in any organization is
 * kept, so no member is silently left without a role. Built-in roles are
 * protected here (not only in the UI) so the invariant lives next to the
 * destructive operation.
 */
class DeleteRoleAction
{
    use AsFake, AsObject;

    /**
     * @throws RoleInUseException While users still hold the role
     */
    public function handle(Role $role): void
    {
        if ($role->built_in) {
            throw new RuntimeException("Cannot delete the built-in role [{$role->name}].");
        }

        if (DB::table(Models::table('assigned_roles'))->where('role_id', $role->getKey())->exists()) {
            throw new RoleInUseException($role);
        }

        $role->delete();

        Bouncer::refresh();
    }
}
