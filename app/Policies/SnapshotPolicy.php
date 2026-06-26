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
     * Operators, Members, Admins and demo users can download.
     */
    public function download(User $user, Snapshot $snapshot): bool
    {
        return $user->isDemo() || $user->canOperate();
    }

    /**
     * Determine whether the user can use this snapshot as the source of a restore.
     * Operators, Members, Admins and demo users can trigger restores. Final
     * authorization on the target server is still checked separately via
     * DatabaseServerPolicy@restore.
     */
    public function restoreFrom(User $user, Snapshot $snapshot): bool
    {
        return $user->isDemo() || $user->canOperate();
    }
}
