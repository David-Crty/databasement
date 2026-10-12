<?php

use App\Actions\Role\SyncUserAbilitiesAction;
use App\Enums\Ability;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Support\BouncerScope;

test('a direct ability grants access on top of the role, without changing it', function () {
    $org = app(CurrentOrganization::class)->model();
    $user = User::factory()->create();

    expect($user->can(Ability::ManageVolumes->value))->toBeFalse();

    SyncUserAbilitiesAction::run($user, [Ability::ManageVolumes->value], $org);

    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeTrue()
        ->and($user->fresh()->roleNameIn($org))->toBe('viewer');
});

test('direct abilities are scoped to the organization', function () {
    $orgA = app(CurrentOrganization::class)->model();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create();

    SyncUserAbilitiesAction::run($user, [Ability::ManageVolumes->value], $orgA);

    BouncerScope::apply($orgA->id);
    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeTrue();

    BouncerScope::apply($orgB->id);
    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeFalse();
});

test('abilities left out of the list are revoked', function () {
    $org = app(CurrentOrganization::class)->model();
    $user = User::factory()->create();
    SyncUserAbilitiesAction::run($user, [Ability::ManageVolumes->value, Ability::RunBackups->value], $org);

    SyncUserAbilitiesAction::run($user, [Ability::RunBackups->value], $org);

    expect($user->directAbilitiesIn($org))->toBe([Ability::RunBackups->value]);
});
