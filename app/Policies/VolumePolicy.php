<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Volume;

class VolumePolicy
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
    public function view(User $user, Volume $volume): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     * Viewers cannot create.
     */
    public function create(User $user): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can update the model.
     * Viewers and demo users cannot update.
     */
    public function update(User $user, Volume $volume): bool
    {
        return $user->canPerformActions() && ! $user->isDemo();
    }

    /**
     * Determine whether the user can delete the model.
     * Viewers and demo users cannot delete.
     */
    public function delete(User $user, Volume $volume): bool
    {
        return $user->canPerformActions() && ! $user->isDemo();
    }
}
