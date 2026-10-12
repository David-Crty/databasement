<?php

namespace App\Actions\Organization;

use App\Actions\Role\AssignRoleToUserAction;
use App\Actions\Role\SyncUserAbilitiesAction;
use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Volume;
use App\Support\BouncerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class MergeOrganizationsAction
{
    use AsFake, AsObject;

    /**
     * Merge all of the source organization's resources into the destination
     * organization, then delete the source.
     *
     * Ownership is reassigned (no data is copied): database servers, volumes,
     * agents and SSH configs are moved to the destination, and members are
     * unioned (a user already in the destination keeps their destination role).
     * Snapshots and backup jobs follow automatically because they derive their
     * organization from their parent database server.
     *
     * @throws InvalidArgumentException When the source is the default organization or the destination itself
     */
    public function handle(Organization $source, Organization $destination, ?int $triggeredByUserId = null): void
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
            BouncerScope::forgetOrganization($source->id);

            return $counts;
        });

        Log::info('Organization merged', [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'destination_id' => $destination->id,
            'destination_name' => $destination->name,
            'actor_user_id' => $triggeredByUserId,
            'reassigned' => $counts,
        ]);
    }

    /**
     * Reassign every row owned by the source org to the destination org,
     * bypassing the organization global scope. Returns the number of rows moved.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function reassign(Builder $query, Organization $source, Organization $destination): int
    {
        return $query
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $source->id)
            ->update(['organization_id' => $destination->id]);
    }

    /**
     * Union the source members into the destination, preserving the role each
     * user already holds in the destination. Members new to the destination
     * carry over their source role and any direct ability grants. Returns the
     * number of members added.
     */
    private function mergeMembers(Organization $source, Organization $destination): int
    {
        $existingUserIds = $destination->users()->pluck('users.id')->all();

        $added = 0;

        foreach ($source->users()->get() as $user) {
            if (in_array($user->id, $existingUserIds, true)) {
                continue;
            }

            $destination->users()->attach($user->id);

            $role = $user->roleNameIn($source) ?? 'viewer';
            AssignRoleToUserAction::run($user, $role, $destination);

            $directAbilities = $user->directAbilitiesIn($source);

            if ($directAbilities !== []) {
                SyncUserAbilitiesAction::run($user, $directAbilities, $destination);
            }

            $added++;
        }

        return $added;
    }
}
