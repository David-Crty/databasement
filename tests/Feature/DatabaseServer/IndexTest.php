<?php

use App\Livewire\DatabaseServer\Index;
use App\Models\DatabaseServer;
use App\Models\User;
use Livewire\Livewire;

test('displays database servers in table', function () {
    $user = User::factory()->create();

    DatabaseServer::factory()->create([
        'name' => 'Production MySQL Server',
        'host' => 'localhost',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Production MySQL Server')
        ->assertSee('localhost');
});

test('shows empty state when no servers exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('No database servers yet');
});

test('can search database servers', function () {
    $user = User::factory()->create();

    DatabaseServer::factory()->create(['name' => 'Production MySQL']);
    DatabaseServer::factory()->create(['name' => 'Development PostgreSQL']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('Production MySQL')
        ->assertDontSee('Development PostgreSQL');
});

test('can sort by column', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class);

    // Default sorting
    expect($component->get('sortBy'))
        ->toBeArray()
        ->toHaveKey('column')
        ->toHaveKey('direction');

    expect($component->get('sortBy')['column'])->toBe('created_at');
    expect($component->get('sortBy')['direction'])->toBe('desc');
});

test('displays pagination when many servers exist', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->count(15)->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class);

    expect($component->viewData('servers')->hasPages())->toBeTrue();
});

test('can delete database server', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['name' => 'Test Server']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDelete', $server->id)
        ->assertSet('deleteId', $server->id)
        ->call('delete')
        ->assertSet('deleteId', null);

    $this->assertDatabaseMissing('database_servers', [
        'id' => $server->id,
    ]);
});
