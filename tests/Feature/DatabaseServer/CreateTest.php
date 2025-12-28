<?php

use App\Facades\DatabaseConnectionTester;
use App\Livewire\DatabaseServer\Create;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
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

test('can create mysql database server', function () {
    DatabaseConnectionTester::shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected!']);

    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Production MySQL Server')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.database_names_input', 'myapp_production')
        ->set('form.description', 'Main production database')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'daily')
        ->set('form.retention_days', 14)
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'Production MySQL Server',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'database_type' => 'mysql',
    ]);

    $server = DatabaseServer::where('name', 'Production MySQL Server')->first();
    expect($server->sqlite_path)->toBeNull();

    $this->assertDatabaseHas('backups', [
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
        'retention_days' => 14,
    ]);
});

test('can create postgresql database server', function () {
    DatabaseConnectionTester::shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected!']);

    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Production PostgreSQL Server')
        ->set('form.host', 'postgres.example.com')
        ->set('form.port', 5432)
        ->set('form.database_type', 'postgresql')
        ->set('form.username', 'pguser')
        ->set('form.password', 'secret123')
        ->set('form.database_names_input', 'myapp_production')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'weekly')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'Production PostgreSQL Server',
        'host' => 'postgres.example.com',
        'port' => 5432,
        'database_type' => 'postgresql',
    ]);

    $server = DatabaseServer::where('name', 'Production PostgreSQL Server')->first();
    expect($server->sqlite_path)->toBeNull();
});

test('can create sqlite database server', function () {
    DatabaseConnectionTester::shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected!']);

    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Local SQLite Database')
        ->set('form.database_type', 'sqlite')
        ->set('form.sqlite_path', '/data/app.sqlite')
        ->set('form.description', 'Local application database')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'daily')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'Local SQLite Database',
        'database_type' => 'sqlite',
        'sqlite_path' => '/data/app.sqlite',
    ]);

    $server = DatabaseServer::where('name', 'Local SQLite Database')->first();
    expect($server->host)->toBeNull();
    expect($server->username)->toBeNull();
});
