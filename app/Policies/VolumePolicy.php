<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;
use App\Models\Volume;
use App\Policies\Concerns\ChecksOrganizationOwnership;

class VolumePolicy
{
    use ChecksOrganizationOwnership;

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
     * All authenticated users in the owning organization can view details.
     */
    public function view(User $user, Volume $volume): bool
    {
        return $this->ownedByCurrentOrganization($volume->organization_id);
    }

    /**
     * Determine whether the user can view the create/edit form.
     * Demo users can view forms but not submit them.
     */
    public function viewForm(User $user, ?Volume $volume = null): bool
    {
        if ($volume && ! $this->ownedByCurrentOrganization($volume->organization_id)) {
            return false;
        }

        return $user->isDemo() || $user->can(Ability::ManageVolumes->value);
    }

    /**
     * Determine whether the user can create models.
     * Viewers and demo users cannot create.
     */
    public function create(User $user): bool
    {
        return $user->can(Ability::ManageVolumes->value);
    }

    /**
     * Determine whether the user can update the model.
     * Viewers and demo users cannot update.
     */
    public function update(User $user, Volume $volume): bool
    {
        return $this->ownedByCurrentOrganization($volume->organization_id)
            && $user->can(Ability::ManageVolumes->value);
    }

    /**
     * Determine whether the user can delete the model.
     * Viewers and demo users cannot delete.
     */
    public function delete(User $user, Volume $volume): bool
    {
        return $this->ownedByCurrentOrganization($volume->organization_id)
            && $user->can(Ability::ManageVolumes->value);
    }
}
