<?php

use App\Livewire\Configuration\CrossOrganizationServersModal;
use App\Livewire\Configuration\Organization as OrganizationScreen;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\CurrentOrganization;
use Livewire\Livewire;

test('a non-super-admin cannot open the cross-organization servers modal', function () {
    $user = User::factory()->create();
    Organization::factory()->create(['name' => 'Secret Tenant']);

    Livewire::actingAs($user)
        ->test(OrganizationScreen::class)
        ->assertDontSee('Servers across organizations');

    Livewire::actingAs($user)
        ->test(CrossOrganizationServersModal::class)
        ->call('openModal')
        ->assertForbidden();
});

test('a super admin sees servers from every organization with their latest backup', function () {
    $admin = User::factory()->superAdmin()->create();
    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);
    $serverA = DatabaseServer::factory()->create(['name' => 'Server A', 'organization_id' => $orgA->id]);
    DatabaseServer::factory()->create(['name' => 'Server B', 'organization_id' => $orgB->id]);

    Snapshot::factory()->forServer($serverA)->failed()->create();

    Livewire::actingAs($admin)
        ->test(OrganizationScreen::class)
        ->assertSee('Servers across organizations');

    Livewire::actingAs($admin)
        ->test(CrossOrganizationServersModal::class)
        ->dispatch('open-cross-organization-servers-modal')
        ->assertSet('showModal', true)
        ->assertSee('Server A')
        ->assertSee('Server B')
        ->assertSee('Org A')
        ->assertSee('Org B')
        ->assertSee('Failed')
        ->assertSee('Never run');
});

test('search matches the organization name as well as the server', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = Organization::factory()->create(['name' => 'Findable Org']);
    DatabaseServer::factory()->create(['name' => 'Wanted Server', 'organization_id' => $org->id]);
    DatabaseServer::factory()->create(['name' => 'Other Server']);

    Livewire::actingAs($admin)
        ->test(CrossOrganizationServersModal::class)
        ->call('openModal')
        ->set('search', 'Findable')
        ->assertSee('Wanted Server')
        ->assertDontSee('Other Server');
});

test('the server list is paginated', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = Organization::factory()->create();
    DatabaseServer::factory()->count(11)->create(['organization_id' => $org->id]);

    $servers = Livewire::actingAs($admin)
        ->test(CrossOrganizationServersModal::class)
        ->call('openModal')
        ->instance()
        ->servers();

    expect($servers->total())->toBe(11)
        ->and($servers->count())->toBe(10);
});

test('opening a server switches the organization context before redirecting', function () {
    $admin = User::factory()->superAdmin()->create();
    $org = Organization::factory()->create();
    $server = DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($admin)
        ->test(CrossOrganizationServersModal::class)
        ->call('openServer', $server->id)
        ->assertRedirect(route('database-servers.show', $server));

    expect(app(CurrentOrganization::class)->id())->toBe($org->id);
});

test('a non-super-admin cannot open another organization\'s server', function () {
    $user = User::factory()->create();
    [, $server] = foreignServer();

    Livewire::actingAs($user)
        ->test(CrossOrganizationServersModal::class)
        ->call('openServer', $server->id)
        ->assertForbidden();
});
