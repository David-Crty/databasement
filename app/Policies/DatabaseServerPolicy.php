<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Facades\AppConfig;
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
     * Determine whether the user can open the Adminer database browser.
     * Requires the feature to be enabled and the user to meet the configured minimum role.
     * Server compatibility (database type, SSH) is checked separately via DatabaseServer::supportsAdminer().
     * Demo users get access when read-only demo credentials are configured (see AdminerController).
     */
    public function adminer(User $user): bool
    {
        if (! AppConfig::get('app.adminer_enabled')) {
            return false;
        }

        if ($user->isDemo()) {
            return config('services.adminer.demo_username') !== null
                && config('services.adminer.demo_password') !== null;
        }

        $requiredRole = UserRole::tryFrom((string) AppConfig::get('app.adminer_role'));

        if ($requiredRole === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $currentRole = $user->currentOrgRole();

        return $currentRole !== null && $currentRole->meetsMinimum($requiredRole);
    }

    /**
     * Determine whether the user can run a backup.
     * Operators, Members, Admins and demo users can trigger backups.
     */
    public function backup(User $user, DatabaseServer $databaseServer): bool
    {
        if ($databaseServer->backups_enabled === false || $databaseServer->backups->isEmpty()) {
            return false;
        }

        return $user->isDemo() || $user->canOperate();
    }

    /**
     * Determine whether the user can restore to a server.
     * Operators, Members, Admins and demo users can trigger restores.
     */
    public function restore(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->isDemo() || $user->canOperate();
    }
}
