<?php

use App\Support\BouncerScope;
use Illuminate\Database\Migrations\Migration;
use Silber\Bouncer\BouncerFacade as Bouncer;

return new class extends Migration
{
    /**
     * Built-in roles that should be granted the edit-snapshots ability,
     * matching the member/admin tier the migrate_organization_roles_to_bouncer
     * migration's builtInRoles() map now defines for fresh installs.
     *
     * @var list<string>
     */
    private array $roles = ['member', 'admin'];

    /**
     * Grant edit-snapshots to the built-in member/admin roles on installations
     * that already ran migrate_organization_roles_to_bouncer before this
     * ability existed, since that migration only seeds on a fresh install and
     * won't replay its updated map on an existing database.
     */
    public function up(): void
    {
        BouncerScope::ensureFlags();

        foreach ($this->roles as $name) {
            $role = Bouncer::role()->where('name', $name)->first();

            if ($role === null) {
                continue;
            }

            Bouncer::allow($role)->to('edit-snapshots');
        }

        Bouncer::refresh();
    }

    /**
     * Revoke edit-snapshots from the built-in member/admin roles.
     */
    public function down(): void
    {
        BouncerScope::ensureFlags();

        foreach ($this->roles as $name) {
            $role = Bouncer::role()->where('name', $name)->first();

            if ($role === null) {
                continue;
            }

            Bouncer::disallow($role)->to('edit-snapshots');
        }

        Bouncer::refresh();
    }
};
