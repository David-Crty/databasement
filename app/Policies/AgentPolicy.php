<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
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
    public function view(User $user, Agent $agent): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     * Viewers and demo users cannot create.
     */
    public function create(User $user): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can update the model.
     * Viewers and demo users cannot update.
     */
    public function update(User $user, Agent $agent): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can delete the model.
     * Viewers and demo users cannot delete.
     */
    public function delete(User $user, Agent $agent): bool
    {
        return $user->canPerformActions();
    }
}
