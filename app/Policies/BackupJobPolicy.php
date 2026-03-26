<?php

namespace App\Policies;

use App\Models\BackupJob;
use App\Models\User;

class BackupJobPolicy
{
    /**
     * Determine whether the user can view the model.
     * All authenticated users can view job logs.
     */
    public function view(User $user, BackupJob $backupJob): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     * Only pending jobs can be deleted (cancelled before they start).
     */
    public function delete(User $user, BackupJob $backupJob): bool
    {
        return $user->canPerformActions() && $backupJob->status === 'pending';
    }
}
