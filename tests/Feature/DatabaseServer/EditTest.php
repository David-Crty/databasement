<?php

use App\Livewire\DatabaseServer\Edit;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
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
        'retention_days' => 7,
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
})->with('database server configs');

test('can change retention policy', function (array $config) {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $server = DatabaseServer::create([
        'name' => 'Test Server',
        'database_type' => 'mysql',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'username' => 'dbuser',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);

    // Start with forever retention (no specific retention days)
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'daily',
        'retention_policy' => Backup::RETENTION_FOREVER,
        'retention_days' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->set('form.retention_policy', $config['policy']);

    // Set policy-specific fields
    foreach ($config['form_fields'] as $field => $value) {
        $component->set($field, $value);
    }

    $component->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('backups', array_merge(
        ['database_server_id' => $server->id],
        $config['expected_backup']
    ));
})->with('retention policies');

test('disabling backups preserves backup config when snapshots exist', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create([
        'name' => 'Server With Snapshots',
        'backups_enabled' => true,
    ]);
    $backup = $server->backup;

    // Create a snapshot that references this backup
    Snapshot::factory()->forServer($server)->create();

    // Disable backups on the server
    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->set('form.backups_enabled', false)
        ->call('save')
        ->assertHasNoErrors();

    $server->refresh();

    // Server should have backups disabled
    // But backup config should still exist (snapshots depend on it)
    expect($server->backups_enabled)->toBeFalse()
        ->and($server->backup)->not->toBeNull()
        ->and(Backup::find($backup->id))->not->toBeNull();

});
