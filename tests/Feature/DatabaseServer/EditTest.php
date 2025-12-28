<?php

use App\Livewire\DatabaseServer\Edit;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use Livewire\Livewire;

test('guests cannot access edit page', function () {
    $server = DatabaseServer::factory()->create();

    $this->get(route('database-servers.edit', $server))
        ->assertRedirect(route('login'));
});

test('authenticated users can access edit page for mysql server', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $server = DatabaseServer::create([
        'name' => 'MySQL Server',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'dbuser',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    $this->actingAs($user)
        ->get(route('database-servers.edit', $server))
        ->assertStatus(200);
});

test('authenticated users can access edit page for postgresql server', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $server = DatabaseServer::create([
        'name' => 'PostgreSQL Server',
        'host' => 'postgres.example.com',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'pguser',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    $this->actingAs($user)
        ->get(route('database-servers.edit', $server))
        ->assertStatus(200);
});

test('authenticated users can access edit page for sqlite server', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $server = DatabaseServer::create([
        'name' => 'SQLite Database',
        'database_type' => 'sqlite',
        'sqlite_path' => '/data/app.sqlite',
    ]);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    $this->actingAs($user)
        ->get(route('database-servers.edit', $server))
        ->assertStatus(200);
});

test('can edit mysql database server', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $server = DatabaseServer::create([
        'name' => 'MySQL Server',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'dbuser',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertSet('form.name', 'MySQL Server')
        ->assertSet('form.host', 'mysql.example.com')
        ->assertSet('form.port', 3306)
        ->assertSet('form.database_type', 'mysql')
        ->assertSet('form.username', 'dbuser')
        ->set('form.name', 'Updated MySQL Server')
        ->set('form.host', 'mysql2.example.com')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'id' => $server->id,
        'name' => 'Updated MySQL Server',
        'host' => 'mysql2.example.com',
    ]);
});

test('can edit postgresql database server', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $server = DatabaseServer::create([
        'name' => 'PostgreSQL Server',
        'host' => 'postgres.example.com',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'pguser',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertSet('form.name', 'PostgreSQL Server')
        ->assertSet('form.host', 'postgres.example.com')
        ->assertSet('form.port', 5432)
        ->assertSet('form.database_type', 'postgresql')
        ->assertSet('form.username', 'pguser')
        ->set('form.name', 'Updated PostgreSQL Server')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'id' => $server->id,
        'name' => 'Updated PostgreSQL Server',
    ]);
});

test('can edit sqlite database server', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $server = DatabaseServer::create([
        'name' => 'SQLite Database',
        'database_type' => 'sqlite',
        'sqlite_path' => '/data/app.sqlite',
    ]);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertSet('form.name', 'SQLite Database')
        ->assertSet('form.database_type', 'sqlite')
        ->assertSet('form.sqlite_path', '/data/app.sqlite')
        ->assertSet('form.host', '')
        ->assertSet('form.username', '')
        ->set('form.name', 'Updated SQLite Database')
        ->set('form.sqlite_path', '/data/new-app.sqlite')
        ->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'id' => $server->id,
        'name' => 'Updated SQLite Database',
        'sqlite_path' => '/data/new-app.sqlite',
    ]);
});
