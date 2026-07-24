<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Models\User;

class RestorePolicy
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
    public function view(User $user, Restore|ScheduledRestore $restore): bool
    {
        return true;
    }

    /**
     * Determine whether the user can start a new restore or schedule one.
     * Requires the operate-restores ability. Final authorization on the target
     * server is still checked via DatabaseServerPolicy@restore.
     */
    public function create(User $user): bool
    {
        return $user->isDemo() || $user->can(Ability::OperateRestores->value);
    }

    /**
     * Determine whether the user can update the scheduled restore.
     * One-shot Restore records are not editable, so update only applies
     * to ScheduledRestore.
     */
    public function update(User $user, ScheduledRestore $restore): bool
    {
        return $user->isDemo() || $user->can(Ability::OperateRestores->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Restore|ScheduledRestore $restore): bool
    {
        return $user->isDemo() || $user->can(Ability::OperateRestores->value);
    }

    /**
     * Determine whether the user can manually run the scheduled restore now.
     */
    public function run(User $user, ScheduledRestore $restore): bool
    {
        return $user->isDemo() || $user->can(Ability::OperateRestores->value);
    }
}
