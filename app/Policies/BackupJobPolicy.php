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
}
