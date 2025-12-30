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

    // Create actual backup file in the volume's directory
    $volumePath = $server->backup->volume->config['path'];
    $filename = 'backup-'.uniqid().'.sql.gz';
    $filePath = $volumePath.'/'.$filename;
    file_put_contents($filePath, 'test backup content');

    $volume = $server->backup->volume;
    $databaseName = $server->database_names[0] ?? 'testdb';

    return Snapshot::create(array_merge([
        'backup_job_id' => $job->id,
        'database_server_id' => $server->id,
        'backup_id' => $server->backup->id,
        'volume_id' => $volume->id,
        'filename' => basename($filePath),
        'file_size' => filesize($filePath),
        'started_at' => now(),
        'database_name' => $databaseName,
        'database_type' => $server->database_type,
        'compression_type' => 'gzip',
        'method' => 'manual',
        'metadata' => Snapshot::generateMetadata($server, $databaseName, $volume),
    ], $attributes));
}

test('can navigate through restore wizard steps', function (string $databaseType) {
    // Create target server
    $targetServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    // Create source server with snapshot
    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
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
})->with(['mysql', 'postgres', 'sqlite']);

test('can queue restore job with valid data', function (string $databaseType) {
    Queue::fake();

    $targetServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $sourceServer = DatabaseServer::factory()->create([
        'database_type' => $databaseType,
    ]);

    $snapshot = createSnapshotForServer($sourceServer, ['database_names' => ['test_db']]);

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
})->with(['mysql', 'postgres', 'sqlite']);

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
        'database_type' => 'postgres',
    ]);

    createSnapshotForServer($postgresServer);

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
})->with(['mysql', 'postgres', 'sqlite']);
