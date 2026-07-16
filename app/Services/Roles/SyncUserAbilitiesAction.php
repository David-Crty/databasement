<?php

namespace App\Services\Roles;

use App\Models\Organization;
use App\Models\User;
use App\Support\BouncerScope;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Syncs the abilities granted directly to a user (in addition to their role) to
 * exactly the given set, scoped to one organization.
 *
 * Like role assignments, direct user abilities are per-org: granting an ability
 * in one org leaves the user's abilities in other orgs untouched. The caller's
 * scope is restored afterwards, and the user's cached abilities are refreshed so
 * the change takes effect immediately.
 */
class SyncUserAbilitiesAction
{
    /**
     * @param  list<string>  $abilities  ability names from the catalogue
     */
    public function execute(User $user, array $abilities, Organization $organization): void
    {
        $previousScope = Bouncer::scope()->get();

        BouncerScope::apply($organization->id);

        try {
            Bouncer::sync($user)->abilities($abilities);
        } finally {
            BouncerScope::apply($previousScope);
        }

        // Defer the cache refresh until after commit when inside a transaction,
        // so it isn't rebuilt from uncommitted data by a concurrent request.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => Bouncer::refreshFor($user));
        } else {
            Bouncer::refreshFor($user);
        }
    }
}
