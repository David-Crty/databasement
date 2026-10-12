<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Models;

/**
 * Centralises the application's Bouncer scoping configuration.
 *
 * Role and ability *definitions* (and the role→ability grants between them) are
 * global — shared across every organization — while role *assignments* (which
 * user has which role) are scoped per organization. This is Bouncer's documented
 * "scoped relations, global role abilities" mode:
 *
 *   - onlyRelations()          keeps the roles/abilities entities unscoped
 *   - dontScopeRoleAbilities() keeps the role→ability grants unscoped
 *   - to($organizationId)      scopes only the user↔role assignments
 *
 * Every place that talks to Bouncer (middleware, seeders, factories, actions,
 * tests) must apply this exact configuration; mixing flags is what makes the
 * read path return wrong results.
 */
class BouncerScope
{
    /**
     * Apply the standard scoping. Pass the current organization id, or null for
     * an unresolved/guest context where no per-org assignment resolves.
     */
    public static function apply(?string $organizationId): void
    {
        Bouncer::scope()
            ->to($organizationId)
            ->onlyRelations()
            ->dontScopeRoleAbilities();
    }

    /**
     * Ensure the global-definitions flags are set without changing the active
     * organization scope.
     *
     * Writing role definitions and their ability grants is global no matter the
     * scope id (onlyRelations / dontScopeRoleAbilities), so the definition
     * actions use this to stay correct even when invoked from a console context,
     * while never clobbering the per-request org scope set by the middleware.
     */
    public static function ensureFlags(): void
    {
        self::apply(Bouncer::scope()->get());
    }

    /**
     * Remove every role assignment and direct ability grant scoped to an
     * organization that no longer exists. Nothing references the scope, so
     * no foreign key removes them with the organization.
     */
    public static function forgetOrganization(string $organizationId): void
    {
        DB::table(Models::table('assigned_roles'))->where('scope', $organizationId)->delete();
        DB::table(Models::table('permissions'))->where('scope', $organizationId)->delete();

        DB::afterCommit(fn () => Bouncer::refresh());
    }
}
