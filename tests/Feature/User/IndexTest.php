<?php

use App\Enums\Ability;
use App\Livewire\User\Index;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
});

describe('access control', function () {
    test('super admin can access user index', function () {
        actingAs($this->admin);

        Livewire::test(Index::class)
            ->assertOk();
    });

    test('manage-users allows access to the user index', function () {
        $user = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($user);

        Livewire::test(Index::class)
            ->assertOk();
    });

    test('without manage-users, the user index is forbidden', function () {
        actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageUsers->value)->create());

        Livewire::test(Index::class)
            ->assertForbidden();
    });
});

test('displays users in current organization', function () {
    actingAs($this->admin);

    User::factory()->create(['name' => 'John Doe']);
    User::factory()->create(['name' => 'Jane Smith']);

    Livewire::test(Index::class)
        ->assertSee('John Doe')
        ->assertSee('Jane Smith');
});

test('can search users by name', function () {
    actingAs($this->admin);

    User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    Livewire::test(Index::class)
        ->set('search', 'John')
        ->assertSee('John Doe')
        ->assertDontSee('Jane Smith');
});

describe('delete', function () {
    test('delete authorization', function (string $actorType, bool $targetSuperAdmin, int $targetOrgCount, string $expected) {
        $actor = $actorType === 'super_admin'
            ? $this->admin
            : User::factory()->withAbilities([Ability::ManageUsers->value])->create();

        $target = $targetSuperAdmin
            ? User::factory()->superAdmin()->create()
            : User::factory()->create();

        if ($targetOrgCount > 1) {
            $secondOrg = Organization::factory()->create();
            attachUserToOrg($target, $secondOrg, 'member');
        }

        actingAs($actor);

        $component = Livewire::test(Index::class)
            ->call('confirmDelete', $target->id);

        match ($expected) {
            'allowed' => tap($component, function ($c) use ($target) {
                $c->assertSet('showDeleteModal', true)
                    ->assertSet('deleteBlockReason', '')
                    ->call('delete')
                    ->assertSet('showDeleteModal', false);
                expect(User::find($target->id))->toBeNull();
            }),
            'forbidden' => $component->assertForbidden(),
            'blocked' => tap($component, function ($c) use ($target) {
                $c->assertSet('showDeleteModal', true)
                    ->assertNotSet('deleteBlockReason', '');
                $c->call('delete');
                expect(User::find($target->id))->not->toBeNull();
            }),
        };
    })->with([
        'super admin deletes regular user (1 org)' => ['super_admin', false, 1, 'allowed'],
        'super admin deletes regular user (2 orgs)' => ['super_admin', false, 2, 'allowed'],
        'super admin deletes super admin (not last)' => ['super_admin', true, 1, 'allowed'],
        'manage-users holder deletes regular user (1 org)' => ['admin', false, 1, 'allowed'],
        'manage-users holder blocked from deleting user in multiple orgs' => ['admin', false, 2, 'blocked'],
        'manage-users holder cannot delete super admin' => ['admin', true, 1, 'forbidden'],
    ]);

    test('cannot delete yourself', function () {
        actingAs($this->admin);

        Livewire::test(Index::class)
            ->call('confirmDelete', $this->admin->id)
            ->assertForbidden();
    });
});

describe('remove from organization', function () {
    test('remove from org authorization', function (string $actorType, bool $targetSuperAdmin, int $targetOrgCount, string $expected) {
        $actor = $actorType === 'super_admin'
            ? $this->admin
            : User::factory()->withAbilities([Ability::ManageUsers->value])->create();

        $target = $targetSuperAdmin
            ? User::factory()->superAdmin()->create()
            : User::factory()->create();

        if ($targetOrgCount > 1) {
            $secondOrg = Organization::factory()->create();
            attachUserToOrg($target, $secondOrg, 'member');
        }

        actingAs($actor);

        $component = Livewire::test(Index::class)
            ->call('confirmRemoveFromOrg', $target->id);

        $currentOrgId = app(\App\Services\CurrentOrganization::class)->id();

        match ($expected) {
            'allowed' => tap($component, function ($c) use ($target, $currentOrgId) {
                $c->assertSet('showRemoveModal', true)
                    ->assertSet('removeBlockReason', '')
                    ->call('removeFromOrg')
                    ->assertSet('showRemoveModal', false);
                expect(User::find($target->id))->not->toBeNull();
                expect($target->fresh()->organizations()->where('organization_id', $currentOrgId)->exists())->toBeFalse();
            }),
            'forbidden' => $component->assertForbidden(),
            'blocked' => tap($component, function ($c) use ($target, $currentOrgId) {
                $c->assertSet('showRemoveModal', true)
                    ->assertNotSet('removeBlockReason', '');
                $c->call('removeFromOrg');
                expect($target->fresh()->organizations()->where('organization_id', $currentOrgId)->exists())->toBeTrue();
            }),
        };
    })->with([
        'super admin removes user (2 orgs)' => ['super_admin', false, 2, 'allowed'],
        'manage-users holder removes user (2 orgs)' => ['admin', false, 2, 'allowed'],
        'super admin blocked from removing user in single org' => ['super_admin', false, 1, 'blocked'],
        'manage-users holder blocked from removing user in single org' => ['admin', false, 1, 'blocked'],
        'manage-users holder cannot remove super admin' => ['admin', true, 2, 'forbidden'],
    ]);

    test('cannot remove yourself from org', function () {
        actingAs($this->admin);

        Livewire::test(Index::class)
            ->call('confirmRemoveFromOrg', $this->admin->id)
            ->assertForbidden();
    });

    test('manage-users does not allow removing a user outside the current organization', function () {
        $actor = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($actor);

        $otherOrg = Organization::factory()->create();
        $outsider = User::factory()->create();
        $outsider->organizations()->sync([$otherOrg->id]); // not a member of the current (default) org

        Livewire::test(Index::class)
            ->call('confirmRemoveFromOrg', $outsider->id)
            ->assertForbidden();
    });
});

describe('invitation link', function () {
    test('super admin can copy invitation link for pending user', function () {
        actingAs($this->admin);

        $pendingUser = User::factory()->create([
            'invitation_token' => 'test-token-123',
            'invitation_accepted_at' => null,
        ]);

        Livewire::test(Index::class)
            ->call('copyInvitationLink', $pendingUser->id)
            ->assertSet('showCopyModal', true)
            ->assertSet('invitationUrl', route('invitation.accept', 'test-token-123'));
    });

    test('manage-users allows copying an invitation link for a pending user', function () {
        $user = User::factory()->withAbilities([Ability::ManageUsers->value])->create();
        actingAs($user);

        $pendingUser = User::factory()->create([
            'invitation_token' => 'test-token-456',
            'invitation_accepted_at' => null,
        ]);

        Livewire::test(Index::class)
            ->call('copyInvitationLink', $pendingUser->id)
            ->assertSet('showCopyModal', true)
            ->assertSet('invitationUrl', route('invitation.accept', 'test-token-456'));
    });
});
