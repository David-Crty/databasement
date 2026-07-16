<?php

namespace App\Policies;

use App\Enums\Ability;
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
        return $user->isDemo() || $user->can(Ability::ManageDatabaseServers->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(Ability::ManageDatabaseServers->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->can(Ability::ManageDatabaseServers->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->can(Ability::ManageDatabaseServers->value);
    }

    /**
     * Determine whether the user can open the Adminer database browser.
     * Requires the feature to be enabled globally (app.adminer_enabled) and the
     * user to hold the use-adminer ability, or to be the demo user (which
     * connects with the read-only demo credentials substituted in
     * AdminerController). Server compatibility (database type, SSH) is checked
     * separately via DatabaseServer::supportsAdminer().
     */
    public function adminer(User $user): bool
    {
        if (! AppConfig::get('app.adminer_enabled')) {
            return false;
        }

        return $user->isDemo() || $user->can(Ability::UseAdminer->value);
    }

    /**
     * Determine whether the user can change the global Adminer feature switch
     * (app.adminer_enabled) on the Application configuration screen. This is a
     * truly global, app-wide concern — not a per-org catalogue ability — so it
     * is reserved for super admins.
     */
    public function manageAdminer(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can run a backup.
     */
    public function backup(User $user, DatabaseServer $databaseServer): bool
    {
        if ($databaseServer->backups_enabled === false || $databaseServer->backups->isEmpty()) {
            return false;
        }

        return $user->isDemo() || $user->can(Ability::RunBackups->value);
    }

    /**
     * Determine whether the user can restore to a server.
     */
    public function restore(User $user, DatabaseServer $databaseServer): bool
    {
        return $user->isDemo() || $user->can(Ability::OperateRestores->value);
    }
}
