<?php

use App\Enums\Ability;
use App\Models\User;

/**
 * The authoritative built-in role → ability mapping, seeded by the
 * migrate_organization_roles_to_bouncer migration. This test is the guardrail
 * that the seeded mapping matches the documented permissions: for every
 * (role, ability) pair it asserts the role grants exactly the abilities it
 * should and denies every other. Viewing is intentionally absent — read access
 * comes from organization membership, not an ability.
 *
 * @var array<string, list<string>>
 */
$operate = ['run-backups', 'download-snapshots', 'operate-restores'];
$manage = ['manage-database-servers', 'manage-volumes', 'manage-agents'];

$mapping = [
    'viewer' => [],
    'operator' => $operate,
    'member' => [...$operate, 'delete-snapshots', 'use-adminer', ...$manage],
    'admin' => [...$operate, 'delete-snapshots', 'use-adminer', ...$manage, 'manage-backup-settings', 'manage-notifications', 'manage-users'],
];

dataset('role ability matrix', function () use ($mapping) {
    $rows = [];

    foreach ($mapping as $role => $granted) {
        foreach (Ability::names() as $ability) {
            $rows["{$role} → {$ability}"] = [$role, $ability, in_array($ability, $granted, true)];
        }
    }

    return $rows;
});

test('built-in role grants exactly its mapped abilities', function (string $role, string $ability, bool $expected) {
    $user = User::factory()->create(['role' => $role]);

    expect($user->can($ability))->toBe($expected);
})->with('role ability matrix');

test('a super admin bypasses every catalogue ability, regardless of role', function () {
    // Viewer role grants nothing, but the super-admin flag bypasses the catalogue.
    $user = User::factory()->create(['role' => 'viewer', 'super_admin' => true]);

    foreach (Ability::names() as $ability) {
        expect($user->can($ability))->toBeTrue();
    }
});
