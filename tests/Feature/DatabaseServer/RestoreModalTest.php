<?php

use App\Jobs\ProcessRestoreJob;
use App\Livewire\DatabaseServer\RestoreModal;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    actingAs($this->user);
});

test('can navigate through restore wizard steps', function (string $databaseType) {
    // Create target server
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    // Create source server with snapshot
    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $snapshot = Snapshot::factory()->forServer($sourceServer)->withFile()->create();

    $component = Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id);

    // Step 1: Select snapshot (shows all compatible snapshots)
    $component->assertSet('currentStep', 1)
        ->assertSee($sourceServer->name)
        ->assertSee($snapshot->database_name)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('selectedSnapshotId', $snapshot->id)
        ->assertSet('currentStep', 2);

    // Step 2: Enter schema name
    $component->assertSet('currentStep', 2);
})->with('database types');

test('can queue restore job with valid data', function (string $databaseType) {
    Queue::fake();

    $targetServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $snapshot = Snapshot::factory()->forServer($sourceServer)->withFile()->create();

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->call('selectSnapshot', $snapshot->id)
        ->set('schemaName', 'restored_db')
        ->call('restore')
        ->assertDispatched('restore-completed');

    // Verify the job was pushed
    Queue::assertPushed(ProcessRestoreJob::class, 1);

    // Verify that Restore and BackupJob records were created
    $restore = \App\Models\Restore::where('snapshot_id', $snapshot->id)
        ->where('target_server_id', $targetServer->id)
        ->first();

    expect($restore)->not->toBeNull();
    expect($restore->schema_name)->toBe('restored_db');
    expect((string) $restore->triggered_by_user_id)->toBe((string) $this->user->id);
    expect($restore->job)->not->toBeNull();
    expect($restore->job->status)->toBe('pending');

    // Verify the job was pushed with the restore ID
    $pushedJob = Queue::pushed(ProcessRestoreJob::class)->first();
    expect($pushedJob->restoreId)->toBe($restore->id);
})->with('database types');

test('only shows compatible servers with same database type', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    // Create MySQL server with snapshot
    $mysqlServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    Snapshot::factory()->forServer($mysqlServer)->withFile()->create();

    // Create PostgreSQL server with snapshot (should NOT be shown)
    $postgresServer = DatabaseServer::factory()->create([
        'database_type' => 'postgres',
    ]);

    Snapshot::factory()->forServer($postgresServer)->withFile()->create();

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->assertSee($mysqlServer->name)
        ->assertDontSee($postgresServer->name);
});

test('can go back to previous steps', function (string $databaseType) {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $snapshot = Snapshot::factory()->forServer($sourceServer)->withFile()->create();

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->assertSet('currentStep', 1)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('currentStep', 2)
        ->call('previousStep')
        ->assertSet('currentStep', 1);
})->with('database types');

test('prevents restoring over the application database', function () {
    Queue::fake();

    // Get the current default connection to set up matching config
    $defaultConnection = config('database.default');

    // Create target server first (before changing config)
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $snapshot = Snapshot::factory()->forServer($sourceServer)->withFile()->create();

    // Configure the app's database connection to match the target server
    // Set driver to mysql so type comparison works
    config([
        "database.connections.{$defaultConnection}.driver" => 'mysql',
        "database.connections.{$defaultConnection}.host" => '127.0.0.1',
        "database.connections.{$defaultConnection}.port" => 3306,
        "database.connections.{$defaultConnection}.database" => 'databasement_app',
    ]);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->call('selectSnapshot', $snapshot->id)
        ->set('schemaName', 'databasement_app') // Same as app's database
        ->call('restore')
        ->assertNotDispatched('restore-completed');

    // Verify no job was dispatched
    Queue::assertNotPushed(ProcessRestoreJob::class);
});

test('can search and filter snapshots', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    Snapshot::factory()->forServer($server)->withFile()->create(['database_name' => 'users_db']);
    Snapshot::factory()->forServer($server)->withFile()->create(['database_name' => 'orders_db']);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->assertSee('users_db')
        ->assertSee('orders_db')
        ->set('snapshotSearch', 'users')
        ->assertSee('users_db')
        ->assertDontSee('orders_db');
});
