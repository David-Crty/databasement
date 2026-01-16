<?php

use App\Facades\DatabaseConnectionTester;
use App\Livewire\DatabaseServer\Create;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\DatabaseListService;
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

test('can create database server', function (array $config) {
    DatabaseConnectionTester::shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected!']);

    // Mock DatabaseListService to avoid connection errors for fake servers
    $this->mock(DatabaseListService::class, function ($mock) {
        $mock->shouldReceive('listDatabases')->andReturn(['myapp_production']);
    });

    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', $config['name'])
        ->set('form.database_type', $config['type'])
        ->set('form.description', 'Test database')
        ->set('form.volume_id', $volume->id)
        ->set('form.recurrence', 'daily')
        ->set('form.retention_days', 14);

    // Set type-specific fields
    if ($config['type'] === 'sqlite') {
        $component->set('form.sqlite_path', $config['sqlite_path']);
    } else {
        $component
            ->set('form.host', $config['host'])
            ->set('form.port', $config['port'])
            ->set('form.username', 'dbuser')
            ->set('form.password', 'secret123')
            ->set('form.database_names_input', 'myapp_production');
    }

    $component->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => $config['name'],
        'database_type' => $config['type'],
    ]);

    $server = DatabaseServer::where('name', $config['name'])->first();

    if ($config['type'] === 'sqlite') {
        expect($server->sqlite_path)->toBe($config['sqlite_path']);
        expect($server->host)->toBeNull();
        expect($server->username)->toBeNull();
    } else {
        expect($server->host)->toBe($config['host']);
        expect($server->port)->toBe($config['port']);
        expect($server->sqlite_path)->toBeNull();
    }

    $this->assertDatabaseHas('backups', [
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
        'retention_days' => 14,
    ]);
})->with([
    'mysql' => [['type' => 'mysql', 'name' => 'MySQL Server', 'host' => 'mysql.example.com', 'port' => 3306]],
    'postgres' => [['type' => 'postgres', 'name' => 'PostgreSQL Server', 'host' => 'postgres.example.com', 'port' => 5432]],
    'sqlite' => [['type' => 'sqlite', 'name' => 'SQLite Database', 'sqlite_path' => '/data/app.sqlite']],
]);

test('can create database server with backups disabled', function () {
    DatabaseConnectionTester::shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected!']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'MySQL Server No Backup')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.backups_enabled', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'MySQL Server No Backup',
        'database_type' => 'mysql',
        'backups_enabled' => false,
    ]);

    $server = DatabaseServer::where('name', 'MySQL Server No Backup')->first();

    // No backup configuration should be created when backups are disabled
    $this->assertDatabaseMissing('backups', [
        'database_server_id' => $server->id,
    ]);
});
