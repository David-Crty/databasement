<?php

use App\Livewire\Agent\Index;
use App\Livewire\Menu\AgentsMenuItem;
use App\Models\Agent;
use App\Models\User;
use Livewire\Livewire;

test('version status compares major and minor with the server', function (?string $serverVersion, ?string $agentVersion, string $expected) {
    config(['app.version' => $serverVersion]);

    $agent = Agent::factory()->online()->make(['version' => $agentVersion]);

    expect($agent->versionStatus())->toBe($expected);
})->with([
    'same minor, other patch' => ['v1.9.0', '1.9.4', 'current'],
    'older minor' => ['v1.9.0', '1.8.7', 'outdated'],
    'older major' => ['v2.0.0', '1.9.0', 'outdated'],
    'minor compared numerically' => ['v1.10.0', '1.9.0', 'outdated'],
    'newer minor' => ['v1.9.0', '1.10.0', 'newer'],
    'no version reported' => ['v1.9.0', null, 'legacy'],
    'untagged agent' => ['v1.9.0', 'dev', 'dev'],
    'untagged server' => [null, '1.9.0', 'unknown'],
]);

test('an agent that never connected has no version status', function () {
    expect(Agent::factory()->make()->versionStatus())->toBe('never');
});

test('the agents page shows each agent version', function () {
    config(['app.version' => 'v1.9.0']);
    $user = User::factory()->withAbilities([])->create();
    Agent::factory()->online()->create(['version' => '1.9.3']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('v1.9.3');
});

test('the agents menu item lists agents that need an update', function () {
    config(['app.version' => 'v1.9.0']);
    $user = User::factory()->withAbilities([])->create();
    Agent::factory()->online()->create(['name' => 'Current Agent', 'version' => '1.9.0']);
    Agent::factory()->online()->create(['name' => 'Old Agent', 'version' => '1.8.2']);
    Agent::factory()->offline()->create(['name' => 'Legacy Agent']);
    Agent::factory()->create(['name' => 'Unused Agent']);

    Livewire::actingAs($user)
        ->test(AgentsMenuItem::class)
        ->assertSee('2 agents need an update')
        ->assertSee('Old Agent')
        ->assertSee('Legacy Agent')
        ->assertDontSee('Current Agent')
        ->assertDontSee('Unused Agent');
});

test('the agents menu item shows no warning when every agent is current', function () {
    config(['app.version' => 'v1.9.0']);
    $user = User::factory()->withAbilities([])->create();
    Agent::factory()->online()->create(['version' => '1.9.5']);

    Livewire::actingAs($user)
        ->test(AgentsMenuItem::class)
        ->assertDontSee('need an update');
});
