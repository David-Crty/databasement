<?php

namespace App\Policies;

use App\Models\Snapshot;
use App\Models\User;

class SnapshotPolicy
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
    public function view(User $user, Snapshot $snapshot): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     * Viewers and demo users cannot delete.
     */
    public function delete(User $user, Snapshot $snapshot): bool
    {
        return $user->canPerformActions();
    }

    /**
     * Determine whether the user can download the snapshot.
     * Demo users can download snapshots.
     */
    public function download(User $user, Snapshot $snapshot): bool
    {
        return $user->isDemo() || $user->canPerformActions();
    }
}
