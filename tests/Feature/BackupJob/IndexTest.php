<?php

use App\Livewire\BackupJob\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('mount opens logs modal when valid job ID provided in URL', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $job = $snapshots[0]->job;

    Livewire::actingAs($user)
        ->withQueryParams(['job' => $job->id])
        ->test(Index::class)
        ->assertSet('showLogsModal', true)
        ->assertSet('selectedJobId', $job->id);
});

test('mount resets selectedJobId and shows error when job does not exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['job' => 'nonexistent-job-id'])
        ->test(Index::class)
        ->assertSet('showLogsModal', false)
        ->assertSet('selectedJobId', null)
        ->assertSee('Job not found: nonexistent-job-id');
});

test('can search backup jobs by server name', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['name' => 'Production MySQL', 'database_names' => ['production_db']]);
    $server2 = DatabaseServer::factory()->create(['name' => 'Development PostgreSQL', 'database_names' => ['development_db']]);

    $snapshots1 = $factory->createSnapshots($server1->backups->first(), 'manual', $user->id);
    $snapshots1[0]->job->update(['status' => 'completed']);

    $snapshots2 = $factory->createSnapshots($server2->backups->first(), 'manual', $user->id);
    $snapshots2[0]->job->update(['status' => 'completed']);

    // Search by server name - check database names to verify filtering
    // (server names appear in the filter dropdown, so we check db names which are row-specific)
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('production_db')
        ->assertDontSee('development_db');
});

test('can filter backup jobs by status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['test_db']]);

    $completedSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $completedSnapshot = $completedSnapshots[0];
    $completedSnapshot->job->update(['status' => 'completed']);
    $completedSnapshot->update(['database_name' => 'completed_db']);

    $failedSnapshots = $factory->createSnapshots($server->backups->first(), 'scheduled', $user->id);
    $failedSnapshot = $failedSnapshots[0];
    $failedSnapshot->job->update(['status' => 'failed']);
    $failedSnapshot->update(['database_name' => 'failed_db']);

    $runningSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $runningSnapshot = $runningSnapshots[0];
    $runningSnapshot->job->update(['status' => 'running']);
    $runningSnapshot->update(['database_name' => 'running_db']);

    // Filter by completed - should only see completed_db
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', 'completed')
        ->assertSee('completed_db')
        ->assertDontSee('failed_db')
        ->assertDontSee('running_db');

    // Filter by failed - should only see failed_db
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', 'failed')
        ->assertSee('failed_db')
        ->assertDontSee('completed_db')
        ->assertDontSee('running_db');
});

test('can filter backup jobs by type', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['test_db']]);

    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshots[0]->job->update(['status' => 'completed']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('typeFilter', 'backup')
        ->assertSee('test_db')
        ->set('typeFilter', 'restore')
        ->assertDontSee('test_db')
        ->call('clear')
        ->assertSet('typeFilter', '')
        ->assertSee('test_db');
});

test('can filter backup jobs by server', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['name' => 'Production Server', 'database_names' => ['production_db']]);
    $server2 = DatabaseServer::factory()->create(['name' => 'Development Server', 'database_names' => ['development_db']]);

    $snapshots1 = $factory->createSnapshots($server1->backups->first(), 'manual', $user->id);
    $snapshots1[0]->job->update(['status' => 'completed']);

    $snapshots2 = $factory->createSnapshots($server2->backups->first(), 'manual', $user->id);
    $snapshots2[0]->job->update(['status' => 'completed']);

    // Filter by server1 - should see only production_db
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('serverFilter', $server1->id)
        ->assertSee('production_db')
        ->assertDontSee('development_db');

    // Filter by server2 - should see only development_db
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('serverFilter', $server2->id)
        ->assertSee('development_db')
        ->assertDontSee('production_db');

    // No filter - should see both
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('serverFilter', '')
        ->assertSee('production_db')
        ->assertSee('development_db');
});

test('can filter jobs by file missing status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['test_db']]);

    // Create a snapshot with missing file
    $missingSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $missingSnapshots[0]->update(['database_name' => 'missing_db', 'file_exists' => false, 'file_verified_at' => now()]);
    $missingSnapshots[0]->job->update(['status' => 'completed']);

    // Create a normal snapshot
    $normalSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $normalSnapshots[0]->update(['database_name' => 'normal_db']);
    $normalSnapshots[0]->job->update(['status' => 'completed']);

    // Filter by file missing - should only see missing_db
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('fileMissing', '1')
        ->assertSee('missing_db')
        ->assertDontSee('normal_db');

    // Without filter - should see both
    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('missing_db')
        ->assertSee('normal_db');
});

test('clear resets file missing filter', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('fileMissing', '1')
        ->call('clear')
        ->assertSet('fileMissing', '');
});

test('can cancel a pending backup job', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshot = $snapshots[0];
    $job = $snapshot->job;

    expect($job->status)->toBe('pending');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmCancelJob', $job->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('cancelJobId', $job->id)
        ->call('deletePendingJob')
        ->assertSet('showDeleteModal', false);

    expect(BackupJob::find($job->id))->toBeNull();
});

test('cannot cancel a non-pending backup job', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshot = $snapshots[0];
    $job = $snapshot->job;

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmCancelJob', $job->id)
        ->assertSet('showDeleteModal', true);

    // Job starts running while modal is open
    $job->markRunning();

    $component
        ->call('deletePendingJob')
        ->assertSet('showDeleteModal', false);

    expect(BackupJob::find($job->id))->not->toBeNull();
});

test('deleting database server cleans up cross-server restore jobs', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $sourceServer = DatabaseServer::factory()->create(['database_names' => ['source_db']]);
    $targetServer = DatabaseServer::factory()->create([
        'database_names' => ['target_db'],
        'database_type' => $sourceServer->database_type,
    ]);

    // Create a backup on source server
    $snapshots = $factory->createSnapshots($sourceServer->backups->first(), 'manual', $user->id);
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'test.sql.gz', 'file_size' => 100]);
    $snapshot->job->markCompleted();

    // Create a cross-server restore (source → target)
    $restore = $factory->createRestore($snapshot, $targetServer, 'restored_db', $user->id);
    $restoreJobId = $restore->job->id;

    // Delete the TARGET server
    $targetServer->skipFileCleanup = true;
    $targetServer->delete();

    // The restore's BackupJob should be cleaned up
    expect(BackupJob::find($restoreJobId))->toBeNull();
});

test('can delete snapshot with file and cascades restores and jobs', function () {
    $user = User::factory()->create();

    // Create volume with temp directory (factory handles directory creation)
    $volume = Volume::factory()->local()->create();
    $tempDir = $volume->config['path'];

    // Create a backup file in the volume directory
    $backupFilename = 'test-backup.sql.gz';
    $backupFilePath = $tempDir.'/'.$backupFilename;
    file_put_contents($backupFilePath, 'test backup content');

    // Create server with backup using our volume
    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $server->backups->first()->update(['volume_id' => $volume->id]);

    // Create snapshot with real file
    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshot = $snapshots[0];
    $snapshot->update([
        'filename' => $backupFilename,
        'file_size' => filesize($backupFilePath),
    ]);
    $snapshot->job->markCompleted();
    $snapshotJobId = $snapshot->job->id;

    // Create a restore record associated with this snapshot
    $restoreJob = BackupJob::create([
        'type' => 'restore',
        'status' => 'completed',
        'started_at' => now(),
        'completed_at' => now(),
    ]);
    $restore = \App\Models\Restore::create([
        'backup_job_id' => $restoreJob->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $server->id,
        'schema_name' => 'restored_db',
        'triggered_by_user_id' => $user->id,
    ]);
    $restoreJobId = $restoreJob->id;
    $restoreId = $restore->id;

    // Verify everything exists before deletion
    expect(file_exists($backupFilePath))->toBeTrue()
        ->and($snapshot->fresh())->not->toBeNull()
        ->and(Restore::find($restoreId))->not->toBeNull()
        ->and(BackupJob::find($snapshotJobId))->not->toBeNull()
        ->and(BackupJob::find($restoreJobId))->not->toBeNull();

    // Delete the snapshot via Livewire
    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deleteSnapshotId', $snapshot->id)
        ->call('deleteSnapshot')
        ->assertSet('showDeleteModal', false);

    // Verify cascade deletion
    expect($snapshot->fresh())->toBeNull('Snapshot should be deleted')
        ->and(Restore::find($restoreId))->toBeNull('Restore should be cascade deleted')
        ->and(BackupJob::find($snapshotJobId))->toBeNull('Snapshot job should be cascade deleted')
        ->and(BackupJob::find($restoreJobId))->toBeNull('Restore job should be cascade deleted')
        ->and(file_exists($backupFilePath))->toBeFalse('Backup file should be deleted from storage');
});
