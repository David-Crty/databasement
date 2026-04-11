<?php

use App\Livewire\DatabaseServer\Create;
use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\Databases\DatabaseProvider;
use Livewire\Livewire;

test('can create database server', function (array $config) {
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
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_days', 14);

    // Set type-specific fields
    if ($config['type'] === 'sqlite') {
        foreach ($config['database_names'] as $i => $name) {
            $component->set("form.database_names.{$i}", $name);
        }
    } elseif ($config['type'] === 'redis') {
        $component
            ->set('form.host', $config['host'])
            ->set('form.port', $config['port']);
    } else {
        $component
            ->set('form.host', $config['host'])
            ->set('form.port', $config['port'])
            ->set('form.username', 'dbuser')
            ->set('form.password', 'secret123')
            ->set('form.database_names.0', 'myapp_production');
    }

    $component->call('save')
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => $config['name'],
        'database_type' => $config['type'],
    ]);

    $server = DatabaseServer::where('name', $config['name'])->first();

    if ($config['type'] === 'sqlite') {
        expect($server->database_names)->toBe(['/data/app.sqlite']);
        expect($server->host)->toBeNull();
        expect($server->username)->toBeNull();
    } else {
        expect($server->host)->toBe($config['host']);
        expect($server->port)->toBe($config['port']);
    }

    $this->assertDatabaseHas('backups', [
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'backup_schedule_id' => dailySchedule()->id,
        'retention_days' => 14,
    ]);
})->with('database server configs');

test('can create database server with backups disabled', function () {
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

test('can create database server with retention policy', function (array $config) {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Test Server')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.database_names.0', 'myapp_production')
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_policy', $config['policy']);

    // Set policy-specific fields
    foreach ($config['form_fields'] as $field => $value) {
        $component->set($field, $value);
    }

    $component->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $server = DatabaseServer::where('name', 'Test Server')->first();

    $this->assertDatabaseHas('backups', array_merge(
        ['database_server_id' => $server->id, 'volume_id' => $volume->id],
        $config['expected_backup']
    ));
})->with('retention policies');

test('cannot create database server with GFS retention when all tiers are empty', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'GFS Validation Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'GFS Empty Tiers Server')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.database_names.0', 'myapp_production')
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_policy', 'gfs')
        ->set('form.gfs_keep_daily', null)
        ->set('form.gfs_keep_weekly', null)
        ->set('form.gfs_keep_monthly', null)
        ->call('save')
        ->assertHasErrors(['form.gfs_keep_daily']);

    $this->assertDatabaseMissing('database_servers', [
        'name' => 'GFS Empty Tiers Server',
    ]);
});

test('can test database connection', function (bool $success, string $message) {
    $user = User::factory()->create();

    $mock = Mockery::mock(DatabaseProvider::class);
    $mock->shouldReceive('testConnectionForServer')
        ->once()
        ->andReturn(['success' => $success, 'message' => $message, 'details' => []]);
    app()->instance(DatabaseProvider::class, $mock);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->call('testConnection')
        ->assertSet('form.connectionTestSuccess', $success)
        ->assertSet('form.connectionTestMessage', $message);
})->with([
    'success' => [true, 'Connection successful'],
    'failure' => [false, 'Connection refused'],
]);

test('can add and remove SQLite database paths', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.database_type', 'sqlite')
        ->assertSet('form.database_names', [''])
        ->set('form.database_names.0', '/data/app.sqlite')
        ->call('addDatabasePath')
        ->assertCount('form.database_names', 2)
        ->set('form.database_names.1', '/data/other.sqlite')
        ->call('removeDatabasePath', 0)
        ->assertCount('form.database_names', 1)
        ->assertSet('form.database_names.0', '/data/other.sqlite');
});

test('can create database server with dump flags', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'MySQL With Flags')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.dump_flags', '--no-tablespaces --column-statistics=0')
        ->set('form.database_names.0', 'myapp')
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_days', 14)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $server = DatabaseServer::where('name', 'MySQL With Flags')->first();

    expect($server->getExtraConfig('dump_flags'))
        ->toBe('--no-tablespaces --column-statistics=0');
});

test('local volume options reflect use_agent state', function (bool $useAgent, bool $expectedDisabled) {
    $user = User::factory()->create();
    $localVolume = Volume::create(['name' => 'Local Vol', 'type' => 'local', 'config' => ['path' => '/backups']]);

    $component = Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.use_agent', $useAgent);

    $options = $component->viewData('form')->getVolumeOptions();
    $local = collect($options)->firstWhere('id', $localVolume->id);

    expect($local['disabled'])->toBe($expectedDisabled);
})->with([
    'disabled when use_agent is true' => [true, true],
    'enabled when use_agent is false' => [false, false],
]);

test('toggling use_agent clears local volume but keeps remote volume', function (string $volumeType, array $volumeConfig, string $expectedVolumeId) {
    $user = User::factory()->create();
    $volume = Volume::create(['name' => 'Test Vol', 'type' => $volumeType, 'config' => $volumeConfig]);

    $expected = $expectedVolumeId === 'keep' ? $volume->id : '';

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.volume_id', $volume->id)
        ->set('form.use_agent', true)
        ->assertSet('form.volume_id', $expected);
})->with([
    'clears local volume' => ['local', ['path' => '/backups'], 'clear'],
    'keeps s3 volume' => ['s3', ['bucket' => 'test'], 'keep'],
]);

test('cannot create agent-backed server with local volume', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $localVolume = Volume::create(['name' => 'Local Vol', 'type' => 'local', 'config' => ['path' => '/backups']]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Agent Server')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'mysql.example.com')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.use_agent', true)
        ->set('form.agent_id', $agent->id)
        ->set('form.volume_id', $localVolume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->call('save')
        ->assertHasErrors(['form.volume_id']);

    $this->assertDatabaseMissing('database_servers', ['name' => 'Agent Server']);
});

test('backup summary is incomplete until volume and schedule are set, then renders the full plan', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Prod Backups',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.database_type', 'mysql')
        ->set('form.connectionTestSuccess', true);

    // No volume, no schedule → incomplete warning
    expect($component->get('form')->isBackupConfigComplete())->toBeFalse();
    $component->assertSee('Configuration incomplete');

    // Fill everything required for the summary
    $component
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.database_selection_mode', 'all')
        ->set('form.retention_policy', 'days')
        ->set('form.retention_days', 30);

    expect($component->get('form')->isBackupConfigComplete())->toBeTrue();

    $component
        ->assertDontSee('Configuration incomplete')
        ->assertSee('Summary')
        ->assertSee('all databases')
        ->assertSee('Prod Backups')
        ->assertSee('Every day at 2:00am (Daily)')
        ->assertSee('the last 30 days');
});

test('retention summary text adapts to each retention policy', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.database_type', 'mysql');

    $form = $component->get('form');

    $component->set('form.retention_policy', 'days')->set('form.retention_days', 1);
    expect($component->get('form')->getSummaryRetentionText())->toBe('the last 1 day');

    $component->set('form.retention_days', 90);
    expect($component->get('form')->getSummaryRetentionText())->toBe('the last 90 days');

    $component
        ->set('form.retention_policy', 'gfs')
        ->set('form.gfs_keep_daily', 7)
        ->set('form.gfs_keep_weekly', 4)
        ->set('form.gfs_keep_monthly', 12);
    expect($component->get('form')->getSummaryRetentionText())
        ->toBe('GFS (7 daily, 4 weekly, 12 monthly)');

    // Singular count renders through trans_choice so locales can inflect
    $component
        ->set('form.gfs_keep_daily', 1)
        ->set('form.gfs_keep_weekly', 0)
        ->set('form.gfs_keep_monthly', 0);
    expect($component->get('form')->getSummaryRetentionText())->toBe('GFS (1 daily)');

    $component->set('form.retention_policy', 'forever');
    expect($component->get('form')->getSummaryRetentionText())->toBe('indefinitely');
});

test('backup summary reports incomplete when retention settings are blank', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Prod Backups',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.database_type', 'mysql')
        ->set('form.connectionTestSuccess', true)
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id);

    // Days policy with blank retention_days → incomplete
    $component
        ->set('form.retention_policy', 'days')
        ->set('form.retention_days', null);
    expect($component->get('form')->isBackupConfigComplete())->toBeFalse();

    // GFS policy with every tier at 0 → incomplete
    $component
        ->set('form.retention_policy', 'gfs')
        ->set('form.gfs_keep_daily', 0)
        ->set('form.gfs_keep_weekly', 0)
        ->set('form.gfs_keep_monthly', 0);
    expect($component->get('form')->isBackupConfigComplete())->toBeFalse();

    // Filling a single tier is enough
    $component->set('form.gfs_keep_daily', 7);
    expect($component->get('form')->isBackupConfigComplete())->toBeTrue();
});
