<?php

namespace App\Services\Roles;

use App\Models\Organization;
use App\Models\User;
use App\Support\BouncerScope;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Assigns a single role to a user within an organization.
 *
 * Role definitions are global, but assignments are per-org: the user's roles in
 * the given org are synced to exactly the provided role, leaving other orgs
 * untouched. Refreshes the user's cached abilities so the change takes effect
 * immediately, without a redeploy.
 */
class AssignRoleToUserAction
{
    public function execute(User $user, string $role, Organization $organization): void
    {
        // The global built-in roles are seeded by migration and custom roles are
        // created before assignment, so the role is guaranteed to exist (Bouncer's
        // syncer would otherwise silently skip an unknown role name).
        //
        // Scope the assignment to this organization (the role lookup stays
        // global), then restore the caller's scope so assigning a role in one
        // org never leaks into the active per-request context.
        $previousScope = Bouncer::scope()->get();

        BouncerScope::apply($organization->id);

        try {
            Bouncer::sync($user)->roles([$role]);
        } finally {
            BouncerScope::apply($previousScope);
        }

        // Refresh the user's cached abilities so the change applies immediately.
        // When inside a transaction (OAuth login, org merge, add-existing-user),
        // defer until after commit so the cache isn't rebuilt from uncommitted
        // data by a concurrent request.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => Bouncer::refreshFor($user));
        } else {
            Bouncer::refreshFor($user);
        }
    }
}
