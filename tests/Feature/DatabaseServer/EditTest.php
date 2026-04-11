<?php

use App\Livewire\DatabaseServer\Edit;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use Livewire\Livewire;

test('can edit database server', function (array $config) {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $schedule = dailySchedule();

    $serverData = [
        'name' => $config['name'],
        'database_type' => $config['type'],
    ];

    $backupDatabaseNames = null;
    if ($config['type'] === 'sqlite') {
        $backupDatabaseNames = ['/data/app.sqlite'];
    } elseif ($config['type'] === 'redis') {
        $serverData['host'] = $config['host'];
        $serverData['port'] = $config['port'];
    } else {
        $serverData['host'] = $config['host'];
        $serverData['port'] = $config['port'];
        $serverData['username'] = 'dbuser';
        $serverData['password'] = 'secret';
        $backupDatabaseNames = ['myapp'];
    }

    $server = DatabaseServer::create($serverData);
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'backup_schedule_id' => $schedule->id,
        'retention_days' => 7,
        'database_selection_mode' => $config['type'] === 'redis'
            ? \App\Enums\DatabaseSelectionMode::All->value
            : \App\Enums\DatabaseSelectionMode::Selected->value,
        'database_names' => $backupDatabaseNames,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertSet('form.name', $config['name'])
        ->assertSet('form.database_type', $config['type']);

    if ($config['type'] === 'sqlite') {
        $component
            ->assertSet('form.backups.0.database_names', ['/data/app.sqlite'])
            ->assertSet('form.host', '')
            ->assertSet('form.username', '')
            ->set('form.name', "Updated {$config['name']}")
            ->set('form.backups.0.database_names', ['/data/new-app.sqlite'])
            ->assertSet('form.backups.0.database_names', ['/data/new-app.sqlite']);
    } elseif ($config['type'] === 'redis') {
        $component
            ->assertSet('form.host', $config['host'])
            ->assertSet('form.port', $config['port'])
            ->set('form.name', "Updated {$config['name']}")
            ->set('form.host', "{$config['type']}2.example.com");
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

    $this->assertDatabaseHas('database_servers', [
        'id' => $server->id,
        'name' => "Updated {$config['name']}",
    ]);

    $server->refresh()->load('backups');

    if ($config['type'] === 'sqlite') {
        expect($server->backups->first()->database_names)->toBe(['/data/new-app.sqlite']);
    } else {
        expect($server->host)->toBe("{$config['type']}2.example.com");
    }
})->with('database server configs');

test('can change retention policy', function (array $config) {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    $schedule = dailySchedule();

    $server = DatabaseServer::create([
        'name' => 'Test Server',
        'database_type' => 'mysql',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'username' => 'dbuser',
        'password' => 'secret',
    ]);

    // Start with forever retention (no specific retention days)
    Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'backup_schedule_id' => $schedule->id,
        'retention_policy' => Backup::RETENTION_FOREVER,
        'retention_days' => null,
        'database_selection_mode' => \App\Enums\DatabaseSelectionMode::Selected->value,
        'database_names' => ['myapp'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->set('form.backups.0.retention_policy', $config['policy']);

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
    $backup = $server->backups->first();

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
        ->and($server->backups->first())->not->toBeNull()
        ->and(Backup::find($backup->id))->not->toBeNull();

});

test('loadDatabases calls form method for non-SQLite servers', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    // The loadDatabases method should not throw for non-SQLite servers
    // It will fail to actually load databases (no real server), but that's expected
    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->call('loadDatabases')
        ->assertSet('form.loadingDatabases', false);
});

test('loadDatabases skips for SQLite servers', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->sqlite()->create();

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->call('loadDatabases')
        // Should remain empty since SQLite doesn't support listing databases
        ->assertSet('form.availableDatabases', []);
});

test('can add and remove SQLite database paths', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->sqlite()->create();

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertCount('form.backups.0.database_names', 1)
        ->call('addDatabasePath', 0)
        ->assertCount('form.backups.0.database_names', 2)
        ->set('form.backups.0.database_names.1', '/data/other.sqlite')
        ->call('removeDatabasePath', 0, 0)
        ->assertCount('form.backups.0.database_names', 1)
        ->assertSet('form.backups.0.database_names.0', '/data/other.sqlite');
});

test('refreshVolumes can be called without error', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    // Just verify the method doesn't throw - testing toast dispatch is framework behavior
    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->call('refreshVolumes')
        ->assertOk();
});

test('pattern mode filters available databases and auto-loads on switch', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server]);

    // Switching to pattern triggers updatedDatabaseSelectionMode auto-load hook
    $component->assertSet('form.availableDatabases', [])
        ->set('form.backups.0.database_selection_mode', 'pattern')
        ->assertSet('form.loadingDatabases', false);

    // Empty pattern returns nothing
    $component->set('form.backups.0.database_include_pattern', '');
    expect($component->instance()->form->getFilteredDatabases(''))->toBe([]);

    // With databases loaded, pattern filters correctly
    $component->set('form.availableDatabases', [
        ['id' => 'prod_users', 'name' => 'prod_users'],
        ['id' => 'prod_orders', 'name' => 'prod_orders'],
        ['id' => 'staging_users', 'name' => 'staging_users'],
    ])->set('form.backups.0.database_include_pattern', '^prod_');

    expect($component->instance()->form->getFilteredDatabases('^prod_'))
        ->toBe(['prod_users', 'prod_orders']);
});

test('admin can select notification channels for a database server', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $email = NotificationChannel::factory()->email()->create(['name' => 'Zebra Alerts']);
    $slack = NotificationChannel::factory()->slack()->create(['name' => 'Alpha Slack']);
    NotificationChannel::factory()->discord()->create(['name' => 'Mid Discord']);

    $server = DatabaseServer::factory()->create(['name' => 'Prod DB', 'database_names' => ['myapp']]);

    $component = Livewire::actingAs($admin)
        ->test(Edit::class, ['server' => $server])
        ->set('form.notification_channel_selection', 'selected');

    // Verify the form exposes the channels ordered by name
    $channels = $component->instance()->form->getNotificationChannels();
    expect($channels->pluck('name')->toArray())->toBe([
        'Alpha Slack',
        'Mid Discord',
        'Zebra Alerts',
    ]);

    // Select two channels and save
    $component
        ->set('form.notification_channel_ids', [$email->id, $slack->id])
        ->call('save')
        ->assertHasNoErrors();

    // Verify the pivot table was synced
    $synced = $server->fresh()->notificationChannels()->pluck('notification_channels.id')->sort()->values()->toArray();
    expect($synced)->toBe(collect([$email->id, $slack->id])->sort()->values()->toArray());
});

test('selected notification channel selection requires at least one channel', function (string $trigger) {
    $admin = User::factory()->create(['role' => 'admin']);
    NotificationChannel::factory()->email()->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['myapp']]);

    Livewire::actingAs($admin)
        ->test(Edit::class, ['server' => $server])
        ->set('form.notification_trigger', $trigger)
        ->set('form.notification_channel_selection', 'selected')
        ->set('form.notification_channel_ids', [])
        ->call('save')
        ->assertHasErrors('form.notification_channel_ids');
})->with([
    'all events' => 'all',
    'success only' => 'success',
    'failure only' => 'failure',
]);

test('notification channel selection is not required when trigger is disabled', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    NotificationChannel::factory()->email()->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['myapp']]);

    // Previously: switching to "selected" then to "none" (disabled) left an
    // invisible `notification_channel_ids` required error that blocked saving.
    Livewire::actingAs($admin)
        ->test(Edit::class, ['server' => $server])
        ->set('form.notification_channel_selection', 'selected')
        ->set('form.notification_channel_ids', [])
        ->set('form.notification_trigger', 'none')
        ->call('save')
        ->assertHasNoErrors('form.notification_channel_ids')
        ->assertRedirect(route('database-servers.index'));
});

test('edit hydrates multiple backup configurations', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withBackups(2)->create();

    expect($server->backups()->count())->toBe(2);

    $component = Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertCount('form.backups', 2);

    $state = $component->get('form')->backups;
    expect($state[0]['id'])->toBe($server->backups[0]->id)
        ->and($state[1]['id'])->toBe($server->backups[1]->id);
});

test('save creates a second backup row when a card is added', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->local()->create();
    $schedule = dailySchedule();
    $server = DatabaseServer::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->call('addBackup')
        ->assertCount('form.backups', 2)
        ->set('form.backups.1.volume_id', $volume->id)
        ->set('form.backups.1.backup_schedule_id', $schedule->id)
        ->set('form.backups.1.retention_policy', 'days')
        ->set('form.backups.1.retention_days', 30)
        ->set('form.backups.1.database_selection_mode', 'all')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $server->refresh()->load('backups');
    expect($server->backups()->count())->toBe(2);
});

test('removing a backup card orphans existing snapshots without deleting them', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withBackups(2)->create();
    $server->load('backups');
    $toRemove = $server->backups[1];

    // Create a snapshot referencing the backup we're about to remove
    $snapshot = Snapshot::factory()->forServer($server)->create([
        'backup_id' => $toRemove->id,
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->assertCount('form.backups', 2)
        ->call('removeBackup', 1)
        ->assertCount('form.backups', 1)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    // Backup row is gone, snapshot is retained with null backup_id
    expect(Backup::find($toRemove->id))->toBeNull()
        ->and(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and(Snapshot::find($snapshot->id)->backup_id)->toBeNull();
});
