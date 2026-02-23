<?php

/**
 * Integration tests for SSH tunnel functionality with real SSH and MySQL services.
 *
 * These tests require the SSH container (linuxserver/openssh-server) and MySQL
 * container to be running. The SSH container must be able to reach MySQL via
 * its Docker service hostname.
 *
 * Run with: php artisan test --filter=SshTunnelTest
 */

use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use Tests\Support\IntegrationTestHelpers;

beforeEach(function () {
    $this->directServer = null;
    $this->restoredDatabaseName = null;
});

afterEach(function () {
    // Cleanup restored database on the external MySQL server
    if ($this->restoredDatabaseName && $this->directServer) {
        try {
            IntegrationTestHelpers::dropDatabase('mysql', $this->directServer, $this->restoredDatabaseName);
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }
});

test('SSH connection succeeds with password auth', function () {
    $sshConfig = IntegrationTestHelpers::createSshConfig();

    $sshTunnelService = app(SshTunnelService::class);
    $result = $sshTunnelService->testConnection($sshConfig);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('SSH connection successful')
        ->and($result['details']['ping_ms'])->toBeGreaterThan(0);
});

test('MySQL connection test succeeds through SSH tunnel', function () {
    $server = IntegrationTestHelpers::createDatabaseServerWithSshTunnel('mysql');
    $server->load('sshConfig');

    $provider = app(DatabaseProvider::class);
    $result = $provider->testConnectionForServer($server);

    expect($result['success'])->toBeTrue()
        ->and($result['details']['ssh_tunnel'])->toBeTrue()
        ->and($result['details']['ssh_host'])->toBe($server->sshConfig->host);
});

test('MySQL backup and restore through SSH tunnel', function () {
    $backupJobFactory = app(BackupJobFactory::class);
    $filesystemProvider = app(FilesystemProvider::class);

    // Create a direct server (for loading data and verifying restore)
    $this->directServer = IntegrationTestHelpers::createDatabaseServer('mysql');

    // Create a tunneled server (for backup and restore operations) pointing at the same database
    $tunneledServer = IntegrationTestHelpers::createDatabaseServerWithSshTunnel('mysql');
    $volume = IntegrationTestHelpers::createVolume('mysql');
    IntegrationTestHelpers::createBackup($tunneledServer, $volume);
    $tunneledServer->load('backup.volume');

    // Load test data via direct connection
    IntegrationTestHelpers::loadTestData('mysql', $this->directServer);

    // Run backup through SSH tunnel
    $snapshots = $backupJobFactory->createSnapshots(
        server: $tunneledServer,
        method: 'manual',
    );
    $snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($snapshot->id);
    $snapshot->refresh();
    $snapshot->load('job');

    $filesystem = $filesystemProvider->getForVolume($snapshot->volume);

    expect($snapshot->job->status)->toBe('completed')
        ->and($snapshot->file_size)->toBeGreaterThan(0)
        ->and($filesystem->fileExists($snapshot->filename))->toBeTrue();

    // Verify job logged SSH tunnel details
    $sshLogs = collect($snapshot->job->logs)
        ->filter(fn (array $log) => str_contains($log['message'] ?? '', 'SSH tunnel'));
    expect($sshLogs)->not->toBeEmpty();

    // Run restore through SSH tunnel to a new database
    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $this->restoredDatabaseName = 'testdb_ssh_restored_'.hrtime(true).$suffix;

    $restore = $backupJobFactory->createRestore(
        snapshot: $snapshot,
        targetServer: $tunneledServer,
        schemaName: $this->restoredDatabaseName,
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    // Verify restore via direct connection
    $pdo = IntegrationTestHelpers::connectToDatabase('mysql', $this->directServer, $this->restoredDatabaseName);
    $stmt = $pdo->query('SHOW TABLES');
    expect($stmt)->not->toBeFalse();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->not->toBeEmpty();
});
