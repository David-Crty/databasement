<?php

use App\Actions\Role\AssignRoleToUserAction;
use App\Actions\Role\CreateRoleAction;
use App\Actions\Role\DeleteRoleAction;
use App\Exceptions\Role\RoleInUseException;
use App\Models\Organization;
use App\Models\User;
use Silber\Bouncer\Database\Role;

test('it deletes a custom role', function () {
    $role = CreateRoleAction::run('temp-role', 'Temporary', []);

    DeleteRoleAction::run($role);

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeFalse();
});

test('a built-in role is never deleted', function () {
    $role = Role::query()->where('name', 'member')->firstOrFail();

    expect(fn () => DeleteRoleAction::run($role))->toThrow(RuntimeException::class, 'built-in role [member]');

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue();
});

test('a role still assigned in an organization is kept', function () {
    $role = CreateRoleAction::run('temp-role', 'Temporary', []);
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization->id);
    AssignRoleToUserAction::run($user, 'temp-role', $organization);

    expect(fn () => DeleteRoleAction::run($role))->toThrow(RoleInUseException::class);

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeTrue()
        ->and($user->roleNameIn($organization))->toBe('temp-role');
});
