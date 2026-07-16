<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\BackupSchedule;
use App\Models\User;

class BackupSchedulePolicy
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
    public function view(User $user, BackupSchedule $backupSchedule): bool
    {
        return true;
    }

    /**
     * Determine whether the user can manage the global backup settings
     * (working directory, compression, job tuning, cleanup/verify) on the
     * Configuration → Backup screen. Same governing ability as schedule CRUD;
     * a separate method because these settings are not tied to a specific
     * BackupSchedule.
     */
    public function manageSettings(User $user): bool
    {
        return $user->can(Ability::ManageBackupSettings->value);
    }

    /**
     * Determine whether the user can create models.
     * Backup schedules are part of backup configuration.
     */
    public function create(User $user): bool
    {
        return $user->can(Ability::ManageBackupSettings->value);
    }

    /**
     * Determine whether the user can update the model.
     * Backup schedules are part of backup configuration.
     */
    public function update(User $user, BackupSchedule $backupSchedule): bool
    {
        return $user->can(Ability::ManageBackupSettings->value);
    }

    /**
     * Determine whether the user can delete the model.
     * Backup schedules are part of backup configuration.
     */
    public function delete(User $user, BackupSchedule $backupSchedule): bool
    {
        return $user->can(Ability::ManageBackupSettings->value);
    }
}
