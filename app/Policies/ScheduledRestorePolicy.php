<?php

namespace App\Policies;

use App\Models\ScheduledRestore;
use App\Models\User;

class ScheduledRestorePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ScheduledRestore $scheduledRestore): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ScheduledRestore $scheduledRestore): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ScheduledRestore $scheduledRestore): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can manually run the scheduled restore now.
     */
    public function run(User $user, ScheduledRestore $scheduledRestore): bool
    {
        return $user->canPerformActions();
    }
}
