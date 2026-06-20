<?php

use App\Livewire\Configuration\Organization;
use App\Models\DatabaseServer;
use App\Models\Organization as OrganizationModel;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('non-super-admin cannot access organizations page', function () {
    $user = User::factory()->admin()->create();
    actingAs($user);

    get(route('configuration.organizations'))
        ->assertForbidden();
});

test('super admin can access organizations page', function () {
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->assertOk()
        ->assertSee('Default');
});

test('super admin can create an organization', function () {
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('createOrganization')
        ->assertHasErrors('newOrgName');

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->set('newOrgName', 'Engineering')
        ->call('createOrganization')
        ->assertRedirect(route('configuration.organizations'));

    expect(OrganizationModel::where('name', 'Engineering')->exists())->toBeTrue();
});

test('super admin can rename a non-main organization', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('openEditModal', $org->id)
        ->set('editOrgName', 'New Name')
        ->call('updateOrganization')
        ->assertRedirect(route('configuration.organizations'));

    expect($org->fresh()->name)->toBe('New Name');
});

test('super admin cannot rename the main organization', function () {
    $admin = User::factory()->superAdmin()->create();
    $defaultOrg = OrganizationModel::default();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('openEditModal', $defaultOrg->id)
        ->assertForbidden();
});

test('super admin can delete an empty non-main organization', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('confirmDelete', $org->id)
        ->call('deleteOrganization')
        ->assertRedirect(route('configuration.organizations'));

    expect(OrganizationModel::find($org->id))->toBeNull();
});

test('super admin cannot delete main organization', function () {
    $admin = User::factory()->superAdmin()->create();
    $defaultOrg = OrganizationModel::default();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('confirmDelete', $defaultOrg->id)
        ->assertForbidden();
});

test('super admin sees warning when deleting organization with resources', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create();
    DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('confirmDelete', $org->id)
        ->assertSet('deleteOrgHasResources', true)
        ->assertSee('still has servers, volumes, or agents. Remove all resources before deleting it.');
});

test('super admin cannot force delete organization with resources', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create();
    DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->set('deleteOrgId', $org->id)
        ->call('deleteOrganization');

    expect(OrganizationModel::find($org->id))->not->toBeNull();
});
