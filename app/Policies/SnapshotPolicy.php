<?php

namespace App\Policies;

use App\Enums\Ability;
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
     * Requires the delete-snapshots ability.
     */
    public function delete(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::DeleteSnapshots->value);
    }

    /**
     * Determine whether the user can download the snapshot.
     * Requires the download-snapshots ability.
     */
    public function download(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::DownloadSnapshots->value);
    }

    /**
     * Determine whether the user can use this snapshot as the source of a restore.
     * Requires the operate-restores ability. Final authorization on the target
     * server is still checked separately via DatabaseServerPolicy@restore.
     */
    public function restoreFrom(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::OperateRestores->value);
    }
}
