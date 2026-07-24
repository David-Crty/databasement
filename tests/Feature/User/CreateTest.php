<?php

use App\Enums\Ability;
use App\Livewire\User\Create;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
});

describe('access control', function () {
    test('super admin can access create page', function () {
        actingAs($this->admin);

        get(route('users.create'))->assertOk();
    });

    test('manage-users allows access to the create page', function () {
        $orgAdmin = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($orgAdmin);

        get(route('users.create'))->assertOk();
    });

    test('without manage-users, the create page is forbidden', function () {
        actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageUsers->value)->create());

        get(route('users.create'))->assertForbidden();
    });
});

describe('invite new user', function () {
    test('creates user with invitation token attached to current org', function () {
        actingAs($this->admin);

        Livewire::test(Create::class)
            ->set('form.name', 'New User')
            ->set('form.email', 'newuser@example.com')
            ->set('form.role', 'member')
            ->call('save')
            ->assertSet('showCopyModal', true)
            ->assertSet('invitationUrl', fn ($url) => str_contains($url, '/invitation/'));

        $user = User::where('email', 'newuser@example.com')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('New User')
            ->and($user->roleNameIn(Organization::default()))->toBe('member')
            ->and($user->invitation_token)->not->toBeNull()
            ->and($user->password)->toBeNull();
    });

    test('super admin can set super admin flag', function () {
        actingAs($this->admin);

        Livewire::test(Create::class)
            ->set('form.name', 'New Super Admin')
            ->set('form.email', 'superadmin@example.com')
            ->set('form.role', 'admin')
            ->set('form.superAdmin', true)
            ->call('save');

        $user = User::where('email', 'superadmin@example.com')->first();
        expect($user->super_admin)->toBeTrue();
    });

    test('non-super-admin cannot set super admin flag', function () {
        $orgAdmin = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($orgAdmin);

        Livewire::test(Create::class)
            ->set('form.name', 'Attempted Super')
            ->set('form.email', 'attempted@example.com')
            ->set('form.role', 'admin')
            ->set('form.superAdmin', true)
            ->call('save');

        $user = User::where('email', 'attempted@example.com')->first();
        expect($user->super_admin)->toBeFalse();
    });
});

describe('add existing user', function () {
    test('admin can add existing user to current organization', function () {
        actingAs($this->admin);

        $otherOrg = Organization::factory()->create();
        $existingUser = User::factory()->create();
        // Move user to other org only, and clear the factory's default-org role
        // assignment (organizations()->sync() only touches the pivot, not the
        // scoped Bouncer role) so the final check validates addExisting(), not a
        // leftover viewer grant.
        $existingUser->organizations()->sync([$otherOrg->id]);
        DB::table('assigned_roles')
            ->where('entity_id', $existingUser->getKey())
            ->where('entity_type', $existingUser->getMorphClass())
            ->where('scope', Organization::default()->id)
            ->delete();
        attachUserToOrg($existingUser, $otherOrg, 'member');

        expect($existingUser->roleNameIn(Organization::default()))->toBeNull();

        Livewire::test(Create::class)
            ->set('existingUserId', $existingUser->id)
            ->set('existingUserRole', 'viewer')
            ->call('addExisting')
            ->assertHasNoErrors();

        expect($existingUser->roleNameIn(Organization::default()))->toBe('viewer');
    });

    test('rejects adding user already in organization', function () {
        actingAs($this->admin);

        $existingUser = User::factory()->create();

        Livewire::test(Create::class)
            ->set('existingUserId', $existingUser->id)
            ->set('existingUserRole', 'member')
            ->call('addExisting')
            ->assertHasErrors('existingUserId');
    });
});
