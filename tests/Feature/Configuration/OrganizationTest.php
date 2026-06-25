<?php

use App\Jobs\DeleteOrganizationJob;
use App\Jobs\MergeOrganizationJob;
use App\Livewire\Configuration\Organization;
use App\Models\DatabaseServer;
use App\Models\Organization as OrganizationModel;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
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

test('super admin queues deletion of an empty non-main organization', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('confirmDelete', $org->id)
        ->call('deleteOrganization')
        ->assertRedirect(route('configuration.organizations'));

    Queue::assertPushed(DeleteOrganizationJob::class, fn ($job) => $job->organizationId === $org->id);
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
        ->assertSee('All servers, volumes, agents and snapshots in this organization will be permanently deleted.');
});

test('super admin queues cascading deletion of organization with resources', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create();
    DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('confirmDelete', $org->id)
        ->call('deleteOrganization')
        ->assertRedirect(route('configuration.organizations'));

    Queue::assertPushed(
        DeleteOrganizationJob::class,
        fn ($job) => $job->organizationId === $org->id && $job->keepFiles === false,
    );
});

test('super admin can keep backup files when deleting organization', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->create();
    $org = OrganizationModel::factory()->create();
    DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('confirmDelete', $org->id)
        ->set('keepFiles', true)
        ->call('deleteOrganization');

    Queue::assertPushed(
        DeleteOrganizationJob::class,
        fn ($job) => $job->organizationId === $org->id && $job->keepFiles === true,
    );
});

test('super admin can queue an organization merge', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->create();
    $source = OrganizationModel::factory()->create();
    $destination = OrganizationModel::factory()->create();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('openMergeModal', $source->id)
        ->set('mergeDestinationId', $destination->id)
        ->call('mergeOrganization')
        ->assertRedirect(route('configuration.organizations'));

    Queue::assertPushed(MergeOrganizationJob::class, fn ($job) => $job->sourceId === $source->id
        && $job->destinationId === $destination->id);
});

test('merge requires a destination different from the source', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->create();
    $source = OrganizationModel::factory()->create();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('openMergeModal', $source->id)
        ->set('mergeDestinationId', $source->id)
        ->call('mergeOrganization')
        ->assertHasErrors('mergeDestinationId');

    Queue::assertNotPushed(MergeOrganizationJob::class);
});

test('super admin cannot merge the default organization as source', function () {
    $admin = User::factory()->superAdmin()->create();
    $defaultOrg = OrganizationModel::default();

    Livewire::actingAs($admin)
        ->test(Organization::class)
        ->call('openMergeModal', $defaultOrg->id)
        ->assertForbidden();
});
