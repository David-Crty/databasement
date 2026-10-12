<?php

namespace App\Actions\Organization;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteOrganizationAction
{
    use AsFake, AsObject;

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
     *
     * @throws InvalidArgumentException When the organization is the default one
     */
    public function handle(Organization $organization, ?int $triggeredByUserId = null, bool $keepFiles = false): void
    {
        if ($organization->is_default) {
            throw new InvalidArgumentException('The default organization cannot be deleted.');
        }

        $organization->skipFileCleanup = $keepFiles;
        $organization->delete();

        Log::info('Organization deleted', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'actor_user_id' => $triggeredByUserId,
            'kept_files' => $keepFiles,
        ]);
    }
}
