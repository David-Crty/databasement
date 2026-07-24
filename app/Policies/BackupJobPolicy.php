<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\CurrentOrganization;

class BackupJobPolicy
{
    /**
     * Determine whether the user can view the model.
     * Members of the job's owning organization (resolved via the related
     * snapshot's server or the restore's target server) may view its logs.
     * Super admins can view any job.
     */
    public function view(User $user, BackupJob $backupJob): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $orgId = $this->resolveOrganizationId($backupJob);

        if (! $orgId) {
            return false;
        }

        return $user->organizations()
            ->wherePivot('organization_id', $orgId)
            ->exists();
    }

    /**
     * Determine whether the user can delete the model.
     * Only pending jobs can be deleted (cancelled before they start), and only
     * by a member of the job's owning org with the ability (evaluated in the
     * current scope). Super admins can cancel any pending job.
     */
    public function delete(User $user, BackupJob $backupJob): bool
    {
        if ($backupJob->status !== BackupJobStatus::Pending) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $orgId = $this->resolveOrganizationId($backupJob);

        return $orgId !== null
            && $orgId === app(CurrentOrganization::class)->model()->id
            && $user->can(Ability::DeleteSnapshots->value);
    }

    /**
     * Resolve the org that owns the job by walking either side of the
     * snapshot / restore relation and reading the related server's
     * organization_id. The OrganizationScope on DatabaseServer is bypassed
     * since the caller may be in a different org.
     */
    private function resolveOrganizationId(BackupJob $backupJob): ?string
    {
        $snapshot = $backupJob->snapshot;
        $restore = $backupJob->restore;

        $serverId = $snapshot
            ? $snapshot->database_server_id
            : ($restore ? $restore->target_server_id : null);

        if (! $serverId) {
            return null;
        }

        return DatabaseServer::withoutGlobalScopes()
            ->find($serverId)
            ?->organization_id;
    }
}
