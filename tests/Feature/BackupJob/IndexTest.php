<?php

use App\Livewire\BackupJob\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use Livewire\Livewire;

test('guests cannot access backup jobs index page', function () {
    $this->get(route('jobs.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access backup jobs index page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('jobs.index'))
        ->assertStatus(200);
});

test('can search backup jobs by server name', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['name' => 'Production MySQL', 'database_names' => ['production_db']]);
    $server2 = DatabaseServer::factory()->create(['name' => 'Development PostgreSQL', 'database_names' => ['development_db']]);

    $snapshots1 = $factory->createSnapshots($server1, 'manual', $user->id);
    $snapshots1[0]->job->update(['status' => 'completed']);

    $snapshots2 = $factory->createSnapshots($server2, 'manual', $user->id);
    $snapshots2[0]->job->update(['status' => 'completed']);

    // Search by server name - check database names to verify filtering
    // (server names appear in the filter dropdown, so we check db names which are row-specific)
    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('production_db')
        ->assertDontSee('development_db');
});

test('can filter backup jobs by multiple statuses', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['test_db']]);

    $completedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $completedSnapshot = $completedSnapshots[0];
    $completedSnapshot->job->update(['status' => 'completed']);
    $completedSnapshot->update(['database_name' => 'completed_db']);

    $failedSnapshots = $factory->createSnapshots($server, 'scheduled', $user->id);
    $failedSnapshot = $failedSnapshots[0];
    $failedSnapshot->job->update(['status' => 'failed']);
    $failedSnapshot->update(['database_name' => 'failed_db']);

    $runningSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $runningSnapshot = $runningSnapshots[0];
    $runningSnapshot->job->update(['status' => 'running']);
    $runningSnapshot->update(['database_name' => 'running_db']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', ['completed', 'failed'])
        ->assertSee('completed_db')
        ->assertSee('failed_db')
        ->assertDontSee('running_db');
});

test('can filter backup jobs by type', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['test_db']]);

    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
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

    $snapshots1 = $factory->createSnapshots($server1, 'manual', $user->id);
    $snapshots1[0]->job->update(['status' => 'completed']);

    $snapshots2 = $factory->createSnapshots($server2, 'manual', $user->id);
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

test('can download snapshot from local storage', function () {
    $user = User::factory()->create();

    // Create volume with temp directory (factory handles directory creation)
    $volume = Volume::factory()->local()->create();
    $tempDir = $volume->config['path'];

    // Create a backup file in the volume directory
    $backupFilename = 'test-backup.sql.gz';
    $backupFilePath = $tempDir.'/'.$backupFilename;
    file_put_contents($backupFilePath, 'test backup content');

    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
    ]);
    $server->backup->update(['volume_id' => $volume->id]);

    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshot = $snapshots[0];
    $snapshot->update([
        'filename' => $backupFilename,
        'file_size' => filesize($backupFilePath),
    ]);
    $snapshot->job->markCompleted();

    // Test download returns file response
    $response = Livewire::actingAs($user)
        ->test(Index::class)
        ->call('download', $snapshot->id);

    $response->assertFileDownloaded($snapshot->filename);
});

test('can download snapshot from s3 storage redirects to presigned url', function () {
    $user = User::factory()->create();

    $volume = Volume::factory()->create([
        'type' => 's3',
        'config' => [
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'test-key',
            'secret' => 'test-secret',
        ],
    ]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
    ]);
    $server->backup->update(['volume_id' => $volume->id]);

    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshot = $snapshots[0];
    $snapshot->update([
        'filename' => 'test-backup.sql.gz',
        'file_size' => 1024,
    ]);
    $snapshot->job->markCompleted();

    // Mock the S3 filesystem to return a presigned URL
    // Note: The S3 adapter handles the prefix automatically, so we just pass the filename
    $mockS3Filesystem = Mockery::mock(Awss3Filesystem::class);
    $mockS3Filesystem->shouldReceive('getPresignedUrl')
        ->once()
        ->with(
            $volume->config,
            $snapshot->filename,
            Mockery::any()
        )
        ->andReturn('https://test-bucket.s3.amazonaws.com/test-backup.sql.gz?presigned=token');

    app()->instance(Awss3Filesystem::class, $mockS3Filesystem);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('download', $snapshot->id)
        ->assertRedirect('https://test-bucket.s3.amazonaws.com/test-backup.sql.gz?presigned=token');
});

test('s3 download presigned url includes volume prefix in key path', function () {
    config([
        'aws.region' => 'us-east-1',
        'aws.s3_endpoint' => 'http://minio:9000',
        'aws.s3_public_endpoint' => 'https://127.0.0.1:9022',
        'aws.use_path_style_endpoint' => true,
        'aws.access_key_id' => 'test-key',
        'aws.secret_access_key' => 'test-secret',
    ]);
    $user = User::factory()->create();

    // Create S3 volume WITH a prefix
    $volume = Volume::factory()->create([
        'type' => 's3',
        'config' => [
            'bucket' => 'my-backup-bucket',
            'prefix' => 'backups/production',
        ],
    ]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['myapp_db'],
    ]);
    $server->backup->update(['volume_id' => $volume->id]);

    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshot = $snapshots[0];
    $snapshot->update([
        'filename' => 'myapp-backup-2024-01-13.sql.gz',
        'file_size' => 2048,
    ]);
    $snapshot->job->markCompleted();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('download', $snapshot->id)
        ->assertRedirectContains('https://127.0.0.1:9022/my-backup-bucket/backups/production/myapp-backup-2024-01-13.sql.gz');
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
    $server->backup->update(['volume_id' => $volume->id]);

    // Create snapshot with real file
    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
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
