<?php

use App\Jobs\ProcessRestoreJob;
use App\Livewire\DatabaseServer\RestoreModal;
use App\Models\BackupJob;
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

function createSnapshotForServer(DatabaseServer $server, array $attributes = []): Snapshot
{
    // Create BackupJob first (required for snapshot)
    $job = BackupJob::create([
        'status' => 'completed',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    return Snapshot::create(array_merge([
        'backup_job_id' => $job->id,
        'database_server_id' => $server->id,
        'backup_id' => $server->backup->id,
        'volume_id' => $server->backup->volume_id,
        'path' => 'test-backup-'.uniqid().'.sql.gz',
        'file_size' => 1024,
        'started_at' => now(),
        'database_name' => $server->database_name ?? 'testdb',
        'database_type' => $server->database_type,
        'database_host' => $server->host,
        'database_port' => $server->port,
        'compression_type' => 'gzip',
        'method' => 'manual',
    ], $attributes));
}

test('modal can be rendered', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->assertOk();
});

test('can navigate through restore wizard steps', function () {
    // Create target server
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    // Create source server with snapshot
    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $snapshot = createSnapshotForServer($sourceServer);

    $component = Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id);

    // Step 1: Select source server
    $component->assertSet('currentStep', 1)
        ->assertSee($sourceServer->name)
        ->call('selectSourceServer', $sourceServer->id)
        ->assertSet('selectedSourceServerId', $sourceServer->id)
        ->assertSet('currentStep', 2);

    // Step 2: Select snapshot
    $component->assertSee($snapshot->database_name)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('selectedSnapshotId', $snapshot->id)
        ->assertSet('currentStep', 3);

    // Step 3: Enter schema name
    $component->assertSet('currentStep', 3);
});

test('can queue restore job with valid data', function () {
    Queue::fake();

    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'postgresql',
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => 'postgresql',
    ]);

    $snapshot = createSnapshotForServer($sourceServer, ['database_name' => 'test_db']);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->call('selectSourceServer', $sourceServer->id)
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
});

test('validates schema name is required', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $snapshot = createSnapshotForServer($sourceServer);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->call('selectSourceServer', $sourceServer->id)
        ->call('selectSnapshot', $snapshot->id)
        ->set('schemaName', '')
        ->call('restore')
        ->assertHasErrors(['schemaName' => 'required']);
});

test('validates schema name format', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $snapshot = createSnapshotForServer($sourceServer);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->call('selectSourceServer', $sourceServer->id)
        ->call('selectSnapshot', $snapshot->id)
        ->set('schemaName', 'invalid-name-with-dashes!')
        ->call('restore')
        ->assertHasErrors(['schemaName' => 'regex']);
});

test('only shows compatible servers with same database type', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    // Create MySQL server with snapshot
    $mysqlServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    createSnapshotForServer($mysqlServer);

    // Create PostgreSQL server with snapshot (should NOT be shown)
    $postgresServer = DatabaseServer::factory()->create([
        'database_type' => 'postgresql',
    ]);

    createSnapshotForServer($postgresServer);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->assertSee($mysqlServer->name)
        ->assertDontSee($postgresServer->name);
});

test('can go back to previous steps', function () {
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
    ]);

    $snapshot = createSnapshotForServer($sourceServer);

    Livewire::test(RestoreModal::class)
        ->dispatch('open-restore-modal', targetServerId: $targetServer->id)
        ->call('selectSourceServer', $sourceServer->id)
        ->assertSet('currentStep', 2)
        ->call('previousStep')
        ->assertSet('currentStep', 1)
        ->call('selectSourceServer', $sourceServer->id)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('currentStep', 3)
        ->call('previousStep')
        ->assertSet('currentStep', 2);
});
