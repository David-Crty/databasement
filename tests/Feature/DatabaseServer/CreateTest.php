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
    $volume = \App\Models\Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Production MySQL Server')
        ->set('form.host', 'localhost')
        ->set('form.port', 3306)
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'root')
        ->set('form.password', 'secret123')
        ->set('form.database_name', 'myapp_production')
        ->set('form.description', 'Main production database')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'daily')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'Production MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
    ]);

    $server = \App\Models\DatabaseServer::where('name', 'Production MySQL Server')->first();
    $this->assertDatabaseHas('backups', [
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);
});

test('shows validation errors when required fields are missing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', '')
        ->set('form.host', '')
        ->set('form.port', 3306)
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'root')
        ->set('form.password', 'secret')
        ->set('form.volume_id', '')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.host', 'form.volume_id']);
});

test('shows validation error for invalid port', function () {
    $user = User::factory()->create();
    $volume = \App\Models\Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Test Server')
        ->set('form.host', 'localhost')
        ->set('form.port', 99999) // Invalid port
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'root')
        ->set('form.password', 'secret')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'daily')
        ->call('save')
        ->assertHasErrors(['form.port']);
});

test('shows success message after creating server', function () {
    $user = User::factory()->create();
    $volume = \App\Models\Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Test Server')
        ->set('form.host', 'localhost')
        ->set('form.port', 3306)
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'root')
        ->set('form.password', 'secret')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'daily')
        ->call('save');

    expect(session('status'))->toBe('Database server created successfully!');
});

test('creates backup with weekly recurrence', function () {
    $user = User::factory()->create();
    $volume = \App\Models\Volume::create([
        'name' => 'Test Volume',
        'type' => 's3',
        'config' => ['bucket' => 'my-bucket', 'prefix' => ''],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Weekly Backup Server')
        ->set('form.host', 'localhost')
        ->set('form.port', 3306)
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'root')
        ->set('form.password', 'secret')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'weekly')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $server = \App\Models\DatabaseServer::where('name', 'Weekly Backup Server')->first();
    expect($server->backup)->not->toBeNull();
    expect($server->backup->recurrence)->toBe('weekly');
    expect($server->backup->volume_id)->toBe($volume->id);
});

test('database server has one backup relationship', function () {
    $volume = \App\Models\Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $server = \App\Models\DatabaseServer::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $backup = $server->backup()->create([
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    expect($server->backup->id)->toBe($backup->id);
    expect($server->backup->volume->id)->toBe($volume->id);
});

test('deleting database server cascades to backup', function () {
    $volume = \App\Models\Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $server = \App\Models\DatabaseServer::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $backup = $server->backup()->create([
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    $backupId = $backup->id;
    $server->delete();

    $this->assertDatabaseMissing('backups', ['id' => $backupId]);
});

test('volume can have multiple backups', function () {
    $volume = \App\Models\Volume::create([
        'name' => 'Shared Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $server1 = \App\Models\DatabaseServer::create([
        'name' => 'Server 1',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $server2 = \App\Models\DatabaseServer::create([
        'name' => 'Server 2',
        'host' => 'localhost',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    $server1->backup()->create([
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    $server2->backup()->create([
        'volume_id' => $volume->id,
        'recurrence' => 'weekly',
    ]);

    expect($volume->backups)->toHaveCount(2);
    expect($volume->backups->pluck('database_server_id'))->toContain($server1->id, $server2->id);
});
