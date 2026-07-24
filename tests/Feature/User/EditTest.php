<?php

use App\Enums\Ability;
use App\Livewire\User\Edit;
use App\Models\OAuthIdentity;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
});

describe('access control', function () {
    test('super admin can edit any user', function () {
        actingAs($this->admin);

        $user = User::factory()->create();

        get(route('users.edit', $user))->assertOk();
    });

    test('manage-users allows editing non-super-admin users in the org', function () {
        $user = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($user);

        $target = User::factory()->create();

        get(route('users.edit', $target))->assertOk();
    });

    test('manage-users does not allow editing super admin users', function () {
        $user = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($user);

        get(route('users.edit', $this->admin))->assertForbidden();
    });

    test('manage-users does not allow editing users outside the org', function () {
        $orgAdmin = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($orgAdmin);

        $otherOrg = Organization::factory()->create();
        $outsideUser = User::factory()->create();
        $outsideUser->organizations()->sync([$otherOrg->id]);
        attachUserToOrg($outsideUser, $otherOrg, 'member');

        get(route('users.edit', $outsideUser))->assertForbidden();
    });

    test('without manage-users, editing a user is forbidden', function () {
        $target = User::factory()->create();
        actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageUsers->value)->create());

        get(route('users.edit', $target))->assertForbidden();
    });
});

test('can update user name email and role', function () {
    actingAs($this->admin);

    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'role' => 'member',
    ]);

    Livewire::test(Edit::class, ['user' => $user])
        ->set('form.name', 'Updated Name')
        ->set('form.email', 'updated@example.com')
        ->set('form.role', 'viewer')
        ->call('save')
        ->assertRedirect(route('users.index'));

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('updated@example.com')
        ->and($user->roleNameIn(Organization::default()))->toBe('viewer');
});

test('cannot remove last super admin', function () {
    actingAs($this->admin);

    expect(User::where('super_admin', true)->count())->toBe(1);

    Livewire::test(Edit::class, ['user' => $this->admin])
        ->set('form.superAdmin', false)
        ->call('save')
        ->assertNoRedirect();

    $this->admin->refresh();
    expect($this->admin->super_admin)->toBeTrue();
});

test('can demote super admin when multiple exist', function () {
    actingAs($this->admin);

    $anotherAdmin = User::factory()->superAdmin()->create();
    expect(User::where('super_admin', true)->count())->toBe(2);

    Livewire::test(Edit::class, ['user' => $anotherAdmin])
        ->set('form.role', 'member')
        ->set('form.superAdmin', false)
        ->call('save')
        ->assertRedirect(route('users.index'));

    $anotherAdmin->refresh();
    expect($anotherAdmin->roleNameIn(Organization::default()))->toBe('member')
        ->and($anotherAdmin->super_admin)->toBeFalse();
});

test('can promote user to admin', function () {
    actingAs($this->admin);

    $member = User::factory()->create(['role' => 'member']);

    Livewire::test(Edit::class, ['user' => $member])
        ->set('form.role', 'admin')
        ->call('save')
        ->assertRedirect(route('users.index'));

    $member->refresh();
    expect($member->roleNameIn(Organization::default()))->toBe('admin');
});

test('oauth user email field is disabled', function () {
    actingAs($this->admin);

    $user = User::factory()->create();
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'oauth-123',
        'email' => $user->email,
    ]);

    Livewire::test(Edit::class, ['user' => $user])
        ->assertSee(__('Email cannot be changed for SSO/OAuth users.'));
});

test('oauth user email is not updated on save', function () {
    actingAs($this->admin);

    $user = User::factory()->create([
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
    ]);
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'provider_user_id' => 'oauth-456',
        'email' => $user->email,
    ]);

    Livewire::test(Edit::class, ['user' => $user])
        ->set('form.name', 'Updated Name')
        ->set('form.email', 'hacked@example.com')
        ->call('save')
        ->assertRedirect(route('users.index'));

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('oauth@example.com');
});
