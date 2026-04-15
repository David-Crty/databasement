<?php

namespace App\Policies;

use App\Models\DatabaseServer;
use App\Models\User;

class DatabaseServerPolicy
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
    public function view(User $user, DatabaseServer $databaseServer): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the create/edit form.
     * Demo users can view forms but not submit them.
     */
    public function viewForm(User $user, ?DatabaseServer $databaseServer = null): bool
    {
        return $user->isDemo() || $user->canPerformActions();
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
    public function update(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can delete the model.
     * Viewers and demo users cannot delete.
     */
    public function delete(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can run a backup.
     * Demo users can trigger backups.
     */
    public function backup(User $user, DatabaseServer $databaseServer): bool
    {
        if ($databaseServer->backups_enabled === false || $databaseServer->backups->isEmpty()) {
            return false;
        }

        return $user->isDemo() || $user->canPerformActions();
    }

    /**
     * Determine whether the user can restore to a server.
     * Demo users can trigger restores.
     */
    public function restore(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->isDemo() || $user->canPerformActions();
    }
}
