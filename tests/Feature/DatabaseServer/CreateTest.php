<?php

use App\Livewire\DatabaseServer\Create;
use App\Models\User;
use Livewire\Livewire;

test('guests cannot access create page', function () {
    $this->get(route('database-servers.create'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access create page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('database-servers.create'))
        ->assertStatus(200);
});

test('can create database server with valid data', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'Production MySQL Server')
        ->set('host', 'localhost')
        ->set('port', 3306)
        ->set('database_type', 'mysql')
        ->set('username', 'root')
        ->set('password', 'secret123')
        ->set('database_name', 'myapp_production')
        ->set('description', 'Main production database')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'Production MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
    ]);
});

test('shows validation errors when required fields are missing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', '')
        ->set('host', '')
        ->set('port', 3306)
        ->set('database_type', 'mysql')
        ->set('username', 'root')
        ->set('password', 'secret')
        ->call('save')
        ->assertHasErrors(['name', 'host']);
});

test('shows validation error for invalid port', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'Test Server')
        ->set('host', 'localhost')
        ->set('port', 99999) // Invalid port
        ->set('database_type', 'mysql')
        ->set('username', 'root')
        ->set('password', 'secret')
        ->call('save')
        ->assertHasErrors(['port']);
});

test('shows success message after creating server', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'Test Server')
        ->set('host', 'localhost')
        ->set('port', 3306)
        ->set('database_type', 'mysql')
        ->set('username', 'root')
        ->set('password', 'secret')
        ->call('save');

    expect(session('status'))->toBe('Database server created successfully!');
});
