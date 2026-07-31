<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\DatabaseServerSshConfig;
use App\Models\User;

class DatabaseServerSshConfigPolicy
{
    /**
     * Determine whether the user can view any models.
     * All authenticated users can view the list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * All authenticated users can view details.
     */
    public function view(User $user, DatabaseServerSshConfig $sshConfig): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     * SSH tunnel configs are part of the database server domain.
     */
    public function create(User $user): bool
    {
        return $user->can(Ability::ManageDatabaseServers->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DatabaseServerSshConfig $sshConfig): bool
    {
        return $user->can(Ability::ManageDatabaseServers->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DatabaseServerSshConfig $sshConfig): bool
    {
        return $user->can(Ability::ManageDatabaseServers->value);
    }
}
