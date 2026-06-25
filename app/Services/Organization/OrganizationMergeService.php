<?php

namespace App\Services\Organization;

use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class OrganizationMergeService
{
    /**
     * Merge all of the source organization's resources into the destination
     * organization, then delete the source.
     *
     * Ownership is reassigned (no data is copied): database servers, volumes,
     * agents and SSH configs are moved to the destination, and members are
     * unioned (a user already in the destination keeps their destination role).
     * Snapshots and backup jobs follow automatically because they derive their
     * organization from their parent database server.
     */
    public function merge(Organization $source, Organization $destination, ?int $actorUserId = null): void
    {
        if ($source->is_default) {
            throw new InvalidArgumentException('The default organization cannot be merged into another organization.');
        }

        if ($source->is($destination)) {
            throw new InvalidArgumentException('An organization cannot be merged into itself.');
        }

        $counts = DB::transaction(function () use ($source, $destination): array {
            $counts = [
                'database_servers' => $this->reassign(DatabaseServer::query(), $source, $destination),
                'volumes' => $this->reassign(Volume::query(), $source, $destination),
                'agents' => $this->reassign(Agent::query(), $source, $destination),
                'ssh_configs' => $this->reassign(DatabaseServerSshConfig::query(), $source, $destination),
                'members' => $this->mergeMembers($source, $destination),
            ];

            $source->delete();

            return $counts;
        });

        Log::info('Organization merged', [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'destination_id' => $destination->id,
            'destination_name' => $destination->name,
            'actor_user_id' => $actorUserId,
            'reassigned' => $counts,
        ]);
    }

    /**
     * Delete an organization, cascading the deletion of its servers, volumes,
     * agents and SSH configs. When $keepFiles is true, backup files on storage
     * are preserved (only database records are removed).
     *
     * This deliberately runs without a wrapping transaction: the cascade deletes
     * backup files from storage (an irreversible side effect), so a rollback
     * would leave the database referencing files that no longer exist. Running
     * unwrapped keeps database rows and storage consistent — both are removed
     * together — and matches every other delete path (server, volume, snapshot),
     * which also clean up files synchronously without a transaction. A mid-cascade
     * failure leaves a partially deleted org, which is safely re-deletable.
     */
    public function delete(Organization $organization, ?int $actorUserId = null, bool $keepFiles = false): void
    {
        if ($organization->is_default) {
            throw new InvalidArgumentException('The default organization cannot be deleted.');
        }

        $organization->skipFileCleanup = $keepFiles;
        $organization->delete();

        Log::info('Organization deleted', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'actor_user_id' => $actorUserId,
            'kept_files' => $keepFiles,
        ]);
    }

    /**
     * Reassign every row owned by the source org to the destination org,
     * bypassing the organization global scope. Returns the number of rows moved.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function reassign(\Illuminate\Database\Eloquent\Builder $query, Organization $source, Organization $destination): int
    {
        return $query
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $source->id)
            ->update(['organization_id' => $destination->id]);
    }

    /**
     * Union the source members into the destination, preserving the role each
     * user already holds in the destination. Returns the number of members added.
     */
    private function mergeMembers(Organization $source, Organization $destination): int
    {
        $existingUserIds = $destination->users()->pluck('users.id')->all();

        $added = 0;

        foreach ($source->users()->withPivot('role')->get() as $user) {
            if (in_array($user->id, $existingUserIds, true)) {
                continue;
            }

            $destination->users()->attach($user->id, [
                'role' => $user->pivot->role, // @phpstan-ignore property.notFound
            ]);

            $added++;
        }

        return $added;
    }
}
