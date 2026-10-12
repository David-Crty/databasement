<?php

namespace App\Policies;

use App\Enums\Ability;
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
     * Determine whether the user can cancel the job.
     * Only a job still in progress can be cancelled, by a member of its
     * owning org (evaluated in the current scope) who may start it again:
     * run-backups for a backup, operate-restores for a restore. Super admins
     * can cancel any job in progress.
     */
    public function cancel(User $user, BackupJob $backupJob): bool
    {
        if (! $backupJob->status->isInProgress()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $orgId = $this->resolveOrganizationId($backupJob);
        $ability = $backupJob->restore()->withoutGlobalScopes()->exists()
            ? Ability::OperateRestores
            : Ability::RunBackups;

        return $orgId !== null
            && $orgId === app(CurrentOrganization::class)->model()->id
            && $user->can($ability->value);
    }

    /**
     * Resolve the org that owns the job by walking either side of the
     * snapshot / restore relation and reading the related server's
     * organization_id.
     *
     * Every scope is bypassed on the way: this answers "who owns this job"
     * precisely when the caller is in a different org, so a scoped read would
     * make every foreign job look ownerless and deny members their own
     * notification deeplinks. Only ownership is derived here; membership is
     * then checked by the caller.
     */
    private function resolveOrganizationId(BackupJob $backupJob): ?string
    {
        $snapshot = $backupJob->snapshot()->withoutGlobalScopes()->first();
        $restore = $backupJob->restore()->withoutGlobalScopes()->first();

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
