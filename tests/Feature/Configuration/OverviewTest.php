<?php

use App\Enums\Ability;
use App\Livewire\Configuration\Overview;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\User;
use App\Services\CurrentOrganization;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('without view-global-overview, the overview page is forbidden', function () {
    $user = User::factory()->withAllAbilitiesExcept(Ability::ViewGlobalOverview->value)->create();
    actingAs($user);

    get(route('configuration.overview'))
        ->assertForbidden();
});

test('with view-global-overview, servers from every organization are visible', function () {
    $user = User::factory()->withAbilities([Ability::ViewGlobalOverview->value])->create();
    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);
    $serverA = DatabaseServer::factory()->create(['name' => 'Server A', 'organization_id' => $orgA->id]);
    $serverB = DatabaseServer::factory()->create(['name' => 'Server B', 'organization_id' => $orgB->id]);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertOk()
        ->assertSee('Server A')
        ->assertSee('Server B')
        ->assertSee('Org A')
        ->assertSee('Org B');

    expect($serverA->organization_id)->not->toBe($serverB->organization_id);
});

test('search filters servers across organizations', function () {
    $user = User::factory()->withAbilities([Ability::ViewGlobalOverview->value])->create();
    $org = Organization::factory()->create();
    DatabaseServer::factory()->create(['name' => 'Findable Server', 'organization_id' => $org->id]);
    DatabaseServer::factory()->create(['name' => 'Other Server', 'organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->set('search', 'Findable')
        ->assertSee('Findable Server')
        ->assertDontSee('Other Server');
});

test('viewing a server switches current organization context and redirects', function () {
    $user = User::factory()->withAbilities([Ability::ViewGlobalOverview->value])->create();
    $org = Organization::factory()->create();
    $server = DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->call('viewServer', $server->id)
        ->assertRedirect(route('database-servers.show', $server));

    expect(app(CurrentOrganization::class)->id())->toBe($org->id);
});

test('viewing a server\'s jobs switches current organization context and redirects to snapshots', function () {
    $user = User::factory()->withAbilities([Ability::ViewGlobalOverview->value])->create();
    $org = Organization::factory()->create();
    $server = DatabaseServer::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->call('viewJobs', $server->id)
        ->assertRedirect(route('snapshots.index', ['serverFilter' => $server->id]));

    expect(app(CurrentOrganization::class)->id())->toBe($org->id);
});
