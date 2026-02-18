<?php

use App\Livewire\DatabaseServer\ConnectionStatus;
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
