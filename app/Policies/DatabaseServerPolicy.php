<?php

namespace App\Policies;

use App\Enums\DatabaseType;
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
     * Scoped users may only see servers they have been granted access to.
     */
    public function view(User $user, DatabaseServer $databaseServer): bool
    {
        if ($user->isScopedUser()) {
            return $user->getServerAccess($databaseServer) !== null;
        }

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
     * Scoped users may trigger backups when their grant includes can_backup.
     */
    public function backup(User $user, DatabaseServer $databaseServer): bool
    {
        if ($databaseServer->backups_enabled === false || $databaseServer->backups->isEmpty()) {
            return false;
        }

        if ($user->isScopedUser()) {
            $access = $user->getServerAccess($databaseServer);

            return $access !== null && $access->can_backup;
        }

        return $user->isDemo() || $user->canPerformActions();
    }

    /**
     * Determine whether the user can restore to a server.
     * Agent-backed and Redis/Valkey servers do not support automated restore.
     * Scoped users may restore only when their grant includes can_restore.
     */
    public function restore(User $user, DatabaseServer $databaseServer): bool
    {
        if ($databaseServer->agent_id !== null || $databaseServer->database_type === DatabaseType::REDIS) {
            return false;
        }

        if ($user->isScopedUser()) {
            $access = $user->getServerAccess($databaseServer);

            return $access !== null && $access->can_restore;
        }

        return $user->isDemo() || $user->canPerformActions();
    }
}
