<?php

use App\Actions\Role\UpdateRoleAction;
use App\Enums\Ability;
use App\Models\User;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

test('it renames the role and replaces its abilities', function () {
    $role = Role::query()->where('name', 'member')->firstOrFail();

    UpdateRoleAction::run($role, 'Team Member', [Ability::RunBackups->value]);

    expect($role->fresh()->title)->toBe('Team Member')
        ->and($role->fresh()->abilities->pluck('name')->all())->toBe(['run-backups']);
});

test('the change takes effect immediately for users holding the role', function () {
    config(['cache.default' => 'array']);
    Bouncer::cache();
    $user = User::factory()->create();

    expect($user->can(Ability::ManageVolumes->value))->toBeFalse();

    UpdateRoleAction::run(Role::query()->where('name', 'viewer')->firstOrFail(), 'Viewer', [Ability::ManageVolumes->value]);

    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeTrue();
});
