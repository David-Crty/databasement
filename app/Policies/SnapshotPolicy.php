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
     * Scoped users may only see snapshots from their granted servers and databases.
     */
    public function view(User $user, Snapshot $snapshot): bool
    {
        if ($user->isScopedUser()) {
            return $this->snapshotIsAccessible($user, $snapshot);
        }

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
     * Scoped users may download when their grant includes can_download for that server/database.
     */
    public function download(User $user, Snapshot $snapshot): bool
    {
        if ($user->isScopedUser()) {
            $access = $user->getServerAccess($snapshot->databaseServer);

            if ($access === null || ! $access->allowsDatabase($snapshot->database_name)) {
                return false;
            }

            return $access->can_download;
        }

        return $user->isDemo() || $user->canPerformActions();
    }

    private function snapshotIsAccessible(User $user, Snapshot $snapshot): bool
    {
        $access = $user->getServerAccess($snapshot->databaseServer);

        if ($access === null) {
            return false;
        }

        return $access->allowsDatabase($snapshot->database_name);
    }
}
