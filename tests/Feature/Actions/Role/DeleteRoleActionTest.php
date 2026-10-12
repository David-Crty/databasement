<?php

use App\Actions\Role\CreateRoleAction;
use App\Actions\Role\DeleteRoleAction;
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
