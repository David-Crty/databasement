<?php

use App\Enums\Ability;
use App\Livewire\User\Edit;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Services\Roles\SyncUserAbilitiesAction;
use App\Support\BouncerScope;
use Livewire\Livewire;

test('a direct ability grants access on top of the role, without changing it', function () {
    $org = app(CurrentOrganization::class)->model();
    $user = User::factory()->create();

    expect($user->can(Ability::ManageVolumes->value))->toBeFalse();

    app(SyncUserAbilitiesAction::class)->execute($user, [Ability::ManageVolumes->value], $org);

    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeTrue()
        ->and($user->fresh()->roleNameIn($org))->toBe('viewer');
});

test('direct abilities are scoped to the organization', function () {
    $orgA = app(CurrentOrganization::class)->model();
    $orgB = Organization::factory()->create();

    $user = User::factory()->create();
    app(SyncUserAbilitiesAction::class)->execute($user, [Ability::ManageVolumes->value], $orgA);

    BouncerScope::apply($orgA->id);
    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeTrue();

    BouncerScope::apply($orgB->id);
    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeFalse();
});

test('a super admin can grant direct abilities through the edit user form', function () {
    $org = app(CurrentOrganization::class)->model();
    $admin = User::factory()->superAdmin()->create();
    $member = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(Edit::class, ['user' => $member])
        ->set('form.abilities', [Ability::UseAdminer->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($member->directAbilitiesIn($org))->toBe([Ability::UseAdminer->value])
        ->and($member->fresh()->can(Ability::UseAdminer->value))->toBeTrue();
});

test('the edit form preloads the user\'s existing direct abilities', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = app(CurrentOrganization::class)->model();
    $member = User::factory()->create();
    app(SyncUserAbilitiesAction::class)->execute($member, [Ability::ManageAgents->value], $org);

    Livewire::actingAs($admin)
        ->test(Edit::class, ['user' => $member])
        ->assertSet('form.abilities', [Ability::ManageAgents->value]);
});

test('manage-users allows granting direct abilities through the edit user form', function () {
    $org = app(CurrentOrganization::class)->model();
    $actor = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
    $member = User::factory()->create();

    Livewire::actingAs($actor)
        ->test(Edit::class, ['user' => $member])
        ->set('form.abilities', [Ability::ManageVolumes->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->can(Ability::ManageVolumes->value))->toBeTrue()
        ->and($member->directAbilitiesIn($org))->toBe([Ability::ManageVolumes->value]);
});

test('manage-users can grant abilities to itself — accepted escalation', function () {
    // Intentional: a manage-users holder can already reach every catalogue ability
    // by assigning itself the Admin role, so self-granting direct abilities adds no
    // privilege. Documented in docs/user-guide/permissions. Granting super_admin
    // stays separately gated (see the user form / UserPolicy).
    $actor = User::factory()->withAbilities([Ability::ManageUsers->value])->create();

    Livewire::actingAs($actor)
        ->test(Edit::class, ['user' => $actor])
        ->set('form.abilities', [Ability::ManageUsers->value, Ability::ManageVolumes->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($actor->fresh()->can(Ability::ManageVolumes->value))->toBeTrue();
});
