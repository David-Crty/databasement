<?php

namespace App\Policies;

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
     * Determine whether the user can start a new restore (from a context
     * where the target server is not yet known).
     * Demo users can trigger restores. Final authorization on the target
     * server is still checked via DatabaseServerPolicy@restore.
     */
    public function create(User $user): bool
    {
        return $user->isDemo() || $user->canPerformActions();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Restore|ScheduledRestore $restore): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can delete a restore record.
     * Viewers and demo users cannot delete.
     */
    public function delete(User $user, Restore|ScheduledRestore $restore): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can manually run the scheduled restore now.
     */
    public function run(User $user, ScheduledRestore $restore): bool
    {
        return $user->canPerformActions();
    }
}
