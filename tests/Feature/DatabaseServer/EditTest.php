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

test('authenticated users can access edit page', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $this->actingAs($user)
        ->get(route('database-servers.edit', $server))
        ->assertStatus(200);
});

test('can edit database server', function (array $config) {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $serverData = [
        'name' => $config['name'],
        'database_type' => $config['type'],
    ];

    if ($config['type'] === 'sqlite') {
        $serverData['sqlite_path'] = $config['sqlite_path'];
    } else {
        $serverData['host'] = $config['host'];
        $serverData['port'] = $config['port'];
        $serverData['username'] = 'dbuser';
        $serverData['password'] = 'secret';
        $serverData['database_names'] = ['myapp'];
    }

    $server = DatabaseServer::create($serverData);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
    ]);

    $component = Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertSet('form.name', $config['name'])
        ->assertSet('form.database_type', $config['type']);

    if ($config['type'] === 'sqlite') {
        $component
            ->assertSet('form.sqlite_path', $config['sqlite_path'])
            ->assertSet('form.host', '')
            ->assertSet('form.username', '')
            ->set('form.name', "Updated {$config['name']}")
            ->set('form.sqlite_path', '/data/new-app.sqlite');
    } else {
        $component
            ->assertSet('form.host', $config['host'])
            ->assertSet('form.port', $config['port'])
            ->assertSet('form.username', 'dbuser')
            ->set('form.name', "Updated {$config['name']}")
            ->set('form.host', "{$config['type']}2.example.com");
    }

    $component->call('save')
        ->assertRedirect(route('database-servers.index'));

    $expectedData = [
        'id' => $server->id,
        'name' => "Updated {$config['name']}",
    ];

    if ($config['type'] === 'sqlite') {
        $expectedData['sqlite_path'] = '/data/new-app.sqlite';
    } else {
        $expectedData['host'] = "{$config['type']}2.example.com";
    }

    $this->assertDatabaseHas('database_servers', $expectedData);
})->with([
    'mysql' => [['type' => 'mysql', 'name' => 'MySQL Server', 'host' => 'mysql.example.com', 'port' => 3306]],
    'postgres' => [['type' => 'postgres', 'name' => 'PostgreSQL Server', 'host' => 'postgres.example.com', 'port' => 5432]],
    'sqlserver' => [['type' => 'sqlserver', 'name' => 'SQL Server', 'host' => 'mssql.example.com', 'port' => 1433]],
    'sqlite' => [['type' => 'sqlite', 'name' => 'SQLite Database', 'sqlite_path' => '/data/app.sqlite']],
]);
