<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;
use App\Services\CurrentOrganization;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     * Only users who can manage users (and super admins) can access the list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Ability::ManageUsers->value);
    }

    /**
     * Determine whether the user can view the model.
     * All authenticated users can view user details.
     */
    public function view(User $user, User $model): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(Ability::ManageUsers->value);
    }

    /**
     * Determine whether the user can update the model.
     * Super admins can update any user. User managers can update non-super-admin
     * users in their current organization.
     */
    public function update(User $user, User $model): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $currentOrg = app(CurrentOrganization::class);

        return $user->can(Ability::ManageUsers->value)
            && ! $model->isSuperAdmin()
            && $model->belongsToOrganization($currentOrg->model());
    }

    /**
     * Determine whether the user can delete the model.
     * Super admins can delete any user (except self).
     * User managers can delete non-super-admin users in their org.
     * Business rules (last SA, multi-org) are checked at action time.
     */
    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $currentOrg = app(CurrentOrganization::class);

        return $user->can(Ability::ManageUsers->value)
            && ! $model->isSuperAdmin()
            && $model->belongsToOrganization($currentOrg->model());
    }

    /**
     * Determine whether the user can remove the model from the current organization.
     * Business rules (single-org check) are checked at action time.
     */
    public function removeFromOrganization(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        if (! $user->isSuperAdmin() && $model->isSuperAdmin()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $currentOrg = app(CurrentOrganization::class);

        return $user->can(Ability::ManageUsers->value)
            && $model->belongsToOrganization($currentOrg->model());
    }

    /**
     * Determine whether the user can copy the invitation link.
     * Available for pending users to those who can manage users.
     */
    public function copyInvitationLink(User $user, User $model): bool
    {
        if ($model->invitation_token === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $currentOrg = app(CurrentOrganization::class);

        return $user->can(Ability::ManageUsers->value)
            && ! $model->isSuperAdmin()
            && $model->belongsToOrganization($currentOrg->model());
    }

    /**
     * Determine whether the user can attach/detach users in the current org.
     */
    public function manageOrgMembership(User $user): bool
    {
        return $user->can(Ability::ManageUsers->value);
    }
}
