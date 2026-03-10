<?php

/**
 * Integration test for downloading snapshots stored on an SFTP volume.
 *
 * Verifies the full flow: backup to SFTP → download via streamed HTTP route.
 * Requires the SSH container (linuxserver/openssh-server) and MySQL container.
 *
 * Run with: php artisan test --filter=SftpDownloadTest
 */

use App\Jobs\ProcessBackupJob;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Tests\Support\IntegrationTestHelpers;

test('can download snapshot stored on SFTP volume via streamed route', function () {
    $user = User::factory()->create();
    $backupJobFactory = app(BackupJobFactory::class);
    $filesystemProvider = app(FilesystemProvider::class);

    // Create SFTP volume pointing at the SSH container
    $volume = IntegrationTestHelpers::createSftpVolume();

    // Create database server and load test data
    $this->directServer = IntegrationTestHelpers::createDatabaseServer('mysql');
    IntegrationTestHelpers::createBackup($this->directServer, $volume);
    $this->directServer->load('backup.volume');
    IntegrationTestHelpers::loadTestData('mysql', $this->directServer);

    // Run a real backup that stores the snapshot on SFTP
    $snapshots = $backupJobFactory->createSnapshots(
        server: $this->directServer,
        method: 'manual',
        triggeredByUserId: $user->id,
    );
    $snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($snapshot->id);
    $snapshot->refresh();
    $snapshot->load(['job', 'volume']);

    $filesystem = $filesystemProvider->getForVolume($snapshot->volume);

    expect($snapshot->job->status)->toBe('completed')
        ->and($snapshot->file_size)->toBeGreaterThan(0)
        ->and($filesystem->fileExists($snapshot->filename))->toBeTrue();

    // Download via the dedicated HTTP route (exercises downloadStream path)
    $response = $this->actingAs($user)
        ->get(route('snapshots.download', $snapshot));

    $response->assertOk()
        ->assertDownload(basename($snapshot->filename));

    // Verify the streamed content is non-empty and matches the file size on SFTP
    expect(strlen($response->streamedContent()))->toBe($snapshot->file_size);
});
