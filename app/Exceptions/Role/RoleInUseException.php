<?php

namespace App\Exceptions\Role;

use Exception;
use Silber\Bouncer\Database\Role;

/**
 * Users still hold a role, in at least one organization, so it cannot be
 * deleted.
 */
class RoleInUseException extends Exception
{
    public function __construct(public readonly Role $role)
    {
        parent::__construct("The role [{$role->name}] is still assigned to users.");
    }
}
