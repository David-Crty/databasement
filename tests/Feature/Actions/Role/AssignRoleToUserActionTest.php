<?php

use App\Actions\Role\AssignRoleToUserAction;
use App\Enums\Ability;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Support\BouncerScope;
use Silber\Bouncer\BouncerFacade as Bouncer;

test('the role applies only in the organization it is assigned in', function () {
    $orgA = app(CurrentOrganization::class)->model();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create();

    AssignRoleToUserAction::run($user, 'admin', $orgB);

    BouncerScope::apply($orgA->id);
    expect($user->fresh()->can(Ability::ManageUsers->value))->toBeFalse();

    BouncerScope::apply($orgB->id);
    expect($user->fresh()->can(Ability::ManageUsers->value))->toBeTrue();
});

test('it replaces the role the user had in that organization', function () {
    $org = app(CurrentOrganization::class)->model();
    $user = User::factory()->create();

    AssignRoleToUserAction::run($user, 'operator', $org);

    expect($user->fresh()->roleNamesIn($org))->toBe(['operator']);
});

test('the caller\'s organization scope is left as it was', function () {
    $current = app(CurrentOrganization::class)->model();

    AssignRoleToUserAction::run(User::factory()->create(), 'admin', Organization::factory()->create());

    expect(Bouncer::scope()->get())->toBe($current->id);
});
