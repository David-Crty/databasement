<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Roles\AssignRoleToUserAction;
use App\Support\BouncerScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\BouncerFacade as Bouncer;

return new class extends Migration
{
    /**
     * The built-in roles and the abilities they grant on a fresh install. This
     * migration is the single source of truth for the seeded defaults now that
     * the App\Enums\UserRole enum no longer exists. After install the abilities
     * are runtime-editable under Configuration → Roles, so this only defines the
     * starting point.
     *
     * Abilities are listed as plain strings (not the App\Enums\Ability enum) and
     * spelled out per role so this migration stays self-contained and replays
     * correctly even if the enum later changes or a case is renamed/removed.
     *
     * There is no demo role: the demo user (see DemoModeMiddleware) is assigned
     * the viewer role, and its access is enforced via User::isDemo() rather than
     * a dedicated role.
     *
     * @return array<string, array{title: string, abilities: list<string>}>
     */
    private function builtInRoles(): array
    {
        return [
            'admin' => [
                'title' => 'Admin',
                'abilities' => [
                    'run-backups',
                    'download-snapshots',
                    'delete-snapshots',
                    'operate-restores',
                    'use-adminer',
                    'manage-database-servers',
                    'manage-volumes',
                    'manage-agents',
                    'manage-backup-settings',
                    'manage-notifications',
                    'manage-users',
                ],
            ],
            'member' => [
                'title' => 'Member',
                'abilities' => [
                    'run-backups',
                    'download-snapshots',
                    'delete-snapshots',
                    'operate-restores',
                    'use-adminer',
                    'manage-database-servers',
                    'manage-volumes',
                    'manage-agents',
                ],
            ],
            'operator' => [
                'title' => 'Operator',
                'abilities' => [
                    'run-backups',
                    'download-snapshots',
                    'operate-restores',
                ],
            ],
            'viewer' => [
                'title' => 'Viewer',
                'abilities' => [],
            ],
        ];
    }

    /**
     * Seed the global built-in roles and their abilities, move each user's
     * per-organization role from the legacy organization_user.role pivot column
     * into a scoped Bouncer assignment, then drop the column. On a fresh
     * database there are no pivot rows yet, so only the seeding and the column
     * drop run.
     */
    public function up(): void
    {
        // Role definitions and their ability grants are global. ensureFlags()
        // keeps the role/ability entities and grants unscoped while preserving
        // any active org scope.
        BouncerScope::ensureFlags();

        foreach ($this->builtInRoles() as $name => $definition) {
            $role = Bouncer::role()->firstOrCreate(
                ['name' => $name],
                ['title' => $definition['title']],
            );

            if (! $role->built_in) {
                $role->forceFill(['built_in' => true])->save();
            }

            foreach ($definition['abilities'] as $ability) {
                Bouncer::allow($role)->to($ability);
            }
        }

        if (! Schema::hasColumn('organization_user', 'role')) {
            return;
        }

        $assignRole = app(AssignRoleToUserAction::class);

        DB::table('organization_user')->whereNotNull('role')->orderBy('user_id')->each(
            function (object $pivot) use ($assignRole): void {
                $org = Organization::find($pivot->organization_id);
                $user = User::find($pivot->user_id);

                if ($org === null || $user === null) {
                    return;
                }

                $assignRole->execute($user, (string) $pivot->role, $org);
            }
        );

        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * Re-add the column and backfill from each user's role assignment.
     */
    public function down(): void
    {
        if (Schema::hasColumn('organization_user', 'role')) {
            return;
        }

        Schema::table('organization_user', function (Blueprint $table) {
            $table->string('role')->default('viewer');
        });

        DB::table('organization_user')->orderBy('user_id')->each(function (object $pivot): void {
            $org = Organization::find($pivot->organization_id);
            $user = User::find($pivot->user_id);

            if ($org === null || $user === null) {
                return;
            }

            DB::table('organization_user')
                ->where('organization_id', $pivot->organization_id)
                ->where('user_id', $pivot->user_id)
                ->update(['role' => $user->roleNameIn($org) ?? 'viewer']);
        });
    }
};
