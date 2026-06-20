<?php

use App\Livewire\DatabaseServer\ConnectionStatus;
use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\Databases\DatabaseProvider;
use Livewire\Livewire;

test('renders success status when connection succeeds', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $this->mock(DatabaseProvider::class, function ($mock) use ($server) {
        $mock->shouldReceive('testConnectionForServer')
            ->once()
            ->withArgs(fn (DatabaseServer $s) => $s->is($server))
            ->andReturn(['success' => true, 'message' => 'Connection successful', 'details' => []]);
    });

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(ConnectionStatus::class, ['server' => $server])
        ->assertSeeHtml('bg-success')
        ->assertDontSeeHtml('bg-error')
        ->assertSee('Connection successful');
});

test('shows agent online when agent has recent heartbeat', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create(['last_heartbeat_at' => now()]);
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldNotReceive('testConnectionForServer');
    });

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(ConnectionStatus::class, ['server' => $server])
        ->assertSeeHtml('bg-success')
        ->assertSee('Agent online');
});

test('shows agent offline when agent has no recent heartbeat', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create(['last_heartbeat_at' => now()->subMinutes(5)]);
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldNotReceive('testConnectionForServer');
    });

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(ConnectionStatus::class, ['server' => $server])
        ->assertSeeHtml('bg-error')
        ->assertSee('Agent offline');
});

test('renders error status when connection fails', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $this->mock(DatabaseProvider::class, function ($mock) use ($server) {
        $mock->shouldReceive('testConnectionForServer')
            ->once()
            ->withArgs(fn (DatabaseServer $s) => $s->is($server))
            ->andReturn(['success' => false, 'message' => 'Connection refused', 'details' => []]);
    });

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(ConnectionStatus::class, ['server' => $server])
        ->assertSeeHtml('bg-error')
        ->assertDontSeeHtml('bg-success')
        ->assertSee('Connection refused');
});
