<?php

namespace App\Actions\Organization;

use App\Actions\DatabaseServer\DeleteDatabaseServerAction;
use App\Actions\Volume\DeleteVolumeAction;
use App\Exceptions\DatabaseServer\DatabaseServerFilesNotDeletedException;
use App\Exceptions\Volume\VolumeFilesNotDeletedException;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteOrganizationAction
{
    use AsFake, AsObject;

    /**
     * Delete an organization with its servers and volumes, through their own
     * delete actions, then the organization itself, whose agents, SSH configs
     * and memberships go with it. When $keepFiles is true, backup files on
     * storage are preserved (only database records are removed).
     *
     * Each server and volume removes its files before its rows, so a failure
     * leaves a partially deleted organization whose remaining resources still
     * track their files, and which is safely re-deletable.
     *
     * @throws InvalidArgumentException When the organization is the default one
     * @throws DatabaseServerFilesNotDeletedException When a server still has files, which keeps it and the organization
     * @throws VolumeFilesNotDeletedException When a volume still holds files, which keeps it and the organization
     */
    public function handle(Organization $organization, ?int $triggeredByUserId = null, bool $keepFiles = false): void
    {
        if ($organization->is_default) {
            throw new InvalidArgumentException('The default organization cannot be deleted.');
        }

        foreach ($organization->databaseServers()->withoutGlobalScope(OrganizationScope::class)->get() as $server) {
            DeleteDatabaseServerAction::run($server, $keepFiles);
        }

        foreach ($organization->volumes()->withoutGlobalScope(OrganizationScope::class)->get() as $volume) {
            DeleteVolumeAction::run($volume, $keepFiles);
        }

        $organization->delete();

        Log::info('Organization deleted', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'actor_user_id' => $triggeredByUserId,
            'kept_files' => $keepFiles,
        ]);
    }
}
