<?php

use App\Enums\Ability;
use App\Livewire\Configuration\Roles;
use App\Livewire\User\Edit as UserEdit;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Services\Roles\AssignRoleToUserAction;
use App\Services\Roles\CreateRoleAction;
use App\Services\Roles\UpdateRoleAction;
use App\Support\BouncerScope;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

function globalRole(string $name): Role
{
    return Role::query()->where('name', $name)->firstOrFail();
}

test('a super admin can create a custom role with the chosen abilities', function () {
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(Roles::class)
        ->call('openCreate')
        ->set('title', 'Backup Operator')
        ->set('abilities', [Ability::RunBackups->value, Ability::ManageVolumes->value])
        ->call('save')
        ->assertHasNoErrors();

    $role = globalRole('backup-operator');

    expect($role->title)->toBe('Backup Operator')
        ->and($role->abilities->pluck('name')->sort()->values()->all())
        ->toBe(['manage-volumes', 'run-backups']);
});

test('a super admin can change a role\'s abilities', function () {
    $admin = User::factory()->superAdmin()->create();
    $member = globalRole('member');

    Livewire::actingAs($admin)
        ->test(Roles::class)
        ->call('openEdit', $member->getKey())
        ->set('abilities', [Ability::RunBackups->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->abilities->pluck('name')->all())->toBe(['run-backups']);
});

test('non-super-admins can view roles but cannot mutate them', function () {
    $user = User::factory()->create();
    $member = globalRole('member');

    Livewire::actingAs($user)
        ->test(Roles::class)
        ->assertOk()
        ->assertSee('Member');

    // Every mutating verb is forbidden by RolePolicy (each halts the request,
    // so exercise them on separate component instances).
    Livewire::actingAs($user)->test(Roles::class)
        ->call('openCreate')->assertForbidden();

    Livewire::actingAs($user)->test(Roles::class)
        ->call('openEdit', $member->getKey())->assertForbidden();

    Livewire::actingAs($user)->test(Roles::class)
        ->call('confirmDelete', $member->getKey())->assertForbidden();
});

test('a super admin can delete a custom role, but built-in roles are protected', function () {
    $admin = User::factory()->superAdmin()->create();
    $custom = app(CreateRoleAction::class)->execute('temp-role', 'Temporary', []);

    Livewire::actingAs($admin)->test(Roles::class)
        ->call('confirmDelete', $custom->getKey())
        ->call('delete');
    expect(Role::query()->whereKey($custom->getKey())->exists())->toBeFalse();

    // Built-in roles are protected: the delete policy forbids removing them.
    $member = globalRole('member');
    Livewire::actingAs($admin)->test(Roles::class)
        ->call('confirmDelete', $member->getKey())
        ->assertForbidden();
    expect(Role::query()->whereKey($member->getKey())->exists())->toBeTrue();
});

test('granting an ability to a global role takes effect immediately, without a redeploy', function () {
    // Enable caching to prove the action refreshes it.
    config(['cache.default' => 'array']);
    Bouncer::cache();

    $user = User::factory()->create();

    // A viewer cannot manage volumes...
    expect($user->can(Ability::ManageVolumes->value))->toBeFalse();

    // ...until a super admin grants that ability to the (global) viewer role at runtime.
    app(UpdateRoleAction::class)->execute(
        globalRole('viewer'),
        'Viewer',
        [Ability::RunBackups->value, Ability::ManageVolumes->value],
    );

    expect($user->fresh()->can(Ability::ManageVolumes->value))->toBeTrue();
});

test('the same global role yields different access in each organization', function () {
    $orgA = app(CurrentOrganization::class)->model();
    $orgB = Organization::factory()->create();

    // Viewer in org A (the default org), Admin in org B — one global role set.
    $user = User::factory()->create();
    app(AssignRoleToUserAction::class)->execute($user, 'admin', $orgB);

    BouncerScope::apply($orgA->id);
    expect($user->fresh()->can(Ability::ManageUsers->value))->toBeFalse();

    BouncerScope::apply($orgB->id);
    expect($user->fresh()->can(Ability::ManageUsers->value))->toBeTrue();
});

test('editing a user preserves their custom role instead of downgrading to member', function () {
    $org = app(CurrentOrganization::class)->model();
    $user = User::factory()->withAbilities([Ability::ManageUsers->value])->create();

    app(CreateRoleAction::class)->execute('release-manager', 'Release Manager', [Ability::DownloadSnapshots->value]);
    $member = User::factory()->create();
    app(AssignRoleToUserAction::class)->execute($member, 'release-manager', $org);

    Livewire::actingAs($user)
        ->test(UserEdit::class, ['user' => $member])
        ->assertSet('form.role', 'release-manager')
        ->set('form.name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->roleNameIn($org))->toBe('release-manager');
});

test('role assignments attach to bigint users scoped by the ULID organization', function () {
    $org = app(CurrentOrganization::class)->model();
    $user = User::factory()->create();

    expect($user->getKey())->toBeInt()
        ->and(strlen($org->id))->toBe(26);

    $assignment = DB::table('assigned_roles')
        ->where('entity_id', $user->getKey())
        ->where('scope', $org->id)
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($user->roleNamesIn($org))->toContain('viewer');
});
