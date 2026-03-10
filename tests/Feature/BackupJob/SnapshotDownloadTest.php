<?php

use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\Awss3Filesystem;

test('can download snapshot from local storage', function () {
    $user = User::factory()->create();

    $volume = Volume::factory()->local()->create();
    $tempDir = $volume->config['path'];

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

    $response = $this->actingAs($user)
        ->get(route('snapshots.download', $snapshot));

    $response->assertOk()
        ->assertDownload($backupFilename);
});

test('download returns 404 when local file is missing', function () {
    $user = User::factory()->create();

    $volume = Volume::factory()->local()->create();

    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
    ]);
    $server->backup->update(['volume_id' => $volume->id]);

    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshot = $snapshots[0];
    $snapshot->update([
        'filename' => 'nonexistent-backup.sql.gz',
        'file_size' => 1024,
    ]);
    $snapshot->job->markCompleted();

    $response = $this->actingAs($user)
        ->get(route('snapshots.download', $snapshot));

    $response->assertNotFound();
});

test('can download snapshot from s3 storage redirects to presigned url', function () {
    $user = User::factory()->create();

    $volume = Volume::factory()->s3()->create();

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

    $mockS3Filesystem = Mockery::mock(Awss3Filesystem::class);
    $mockS3Filesystem->shouldReceive('getPresignedUrl')
        ->once()
        ->with(
            $volume->getDecryptedConfig(),
            $snapshot->filename,
            Mockery::any()
        )
        ->andReturn('https://test-bucket.s3.amazonaws.com/test-backup.sql.gz?presigned=token');

    app()->instance(Awss3Filesystem::class, $mockS3Filesystem);

    $response = $this->actingAs($user)
        ->get(route('snapshots.download', $snapshot));

    $response->assertRedirect('https://test-bucket.s3.amazonaws.com/test-backup.sql.gz?presigned=token');
});

test('s3 download presigned url includes volume prefix in key path', function () {
    $user = User::factory()->create();

    $volume = Volume::factory()->create([
        'type' => 's3',
        'config' => [
            'bucket' => 'my-backup-bucket',
            'prefix' => 'backups/production',
            'region' => 'us-east-1',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'custom_endpoint' => 'http://minio:9000',
            'public_endpoint' => 'https://127.0.0.1:9022',
            'use_path_style_endpoint' => true,
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

    $response = $this->actingAs($user)
        ->get(route('snapshots.download', $snapshot));

    $response->assertRedirectContains('https://127.0.0.1:9022/my-backup-bucket/backups/production/myapp-backup-2024-01-13.sql.gz');
});

test('guests cannot download snapshots', function () {
    $volume = Volume::factory()->local()->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $server->backup->update(['volume_id' => $volume->id]);

    $factory = app(BackupJobFactory::class);
    $snapshots = $factory->createSnapshots($server, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->job->markCompleted();

    $response = $this->get(route('snapshots.download', $snapshot));

    $response->assertRedirect(route('login'));
});
