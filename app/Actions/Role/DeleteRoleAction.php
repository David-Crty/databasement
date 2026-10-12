<?php

namespace App\Actions\Role;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

/**
 * Deletes a custom role. Role assignments and ability grants are removed via the
 * database foreign keys. Refreshes Bouncer so affected users lose the role
 * immediately. Built-in roles are protected here (not only in the UI) so the
 * invariant lives next to the destructive operation.
 */
class DeleteRoleAction
{
    use AsFake, AsObject;

    public function handle(Role $role): void
    {
        if ($role->built_in) {
            throw new RuntimeException("Cannot delete the built-in role [{$role->name}].");
        }

        $role->delete();

        Bouncer::refresh();
    }
}
