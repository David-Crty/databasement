<?php

/**
 * Integration tests for backup and restore with real databases.
 *
 * These tests require MySQL and PostgreSQL containers to be running.
 * Run with: php artisan test --group=integration
 */

use App\Models\Backup;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\RestoreTask;
use Tests\Support\IntegrationTestHelpers;

beforeEach(function () {
    $this->backupTask = app(BackupTask::class);
    $this->restoreTask = app(RestoreTask::class);
    $this->backupJobFactory = app(BackupJobFactory::class);
    $this->filesystemProvider = app(FilesystemProvider::class);

    $this->volume = null;
    $this->databaseServer = null;
    $this->backup = null;
    $this->snapshot = null;
    $this->restoredDatabaseName = null;
});

afterEach(function () {
    // Cleanup restored database
    if ($this->restoredDatabaseName && $this->databaseServer) {
        try {
            $type = match ($this->databaseServer->database_type) {
                'postgres' => 'postgres',
                default => 'mysql',
            };
            IntegrationTestHelpers::dropDatabase(
                $type,
                $this->databaseServer,
                $this->restoredDatabaseName
            );
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    // Delete models (cascade handles backup and snapshots)
    $this->databaseServer?->delete();
    $this->volume?->delete();
});

test('client-server database backup and restore workflow', function (string $type, string $compression, string $expectedExt) {
    // Set compression method
    config(['backup.compression' => $compression]);

    // Clear singleton bindings and recreate BackupTask with new compression config
    app()->forgetInstance(\App\Services\Backup\CompressorInterface::class);
    app()->forgetInstance(\App\Services\Backup\BackupTask::class);
    $this->backupTask = app(BackupTask::class);

    // Create models
    $this->volume = IntegrationTestHelpers::createVolume($type);
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer($type);
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backup.volume');

    // Load test data
    IntegrationTestHelpers::loadTestData($type, $this->databaseServer);

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        server: $this->databaseServer,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    $this->backupTask->run($this->snapshot);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->volume);

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->filename)->toEndWith(".sql.{$expectedExt}")
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    // Run restore (use unique name with parallel token and microseconds to avoid collisions)
    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $this->restoredDatabaseName = 'testdb_restored_'.hrtime(true).$suffix;
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    $this->restoreTask->run($restore);

    // Verify restore
    $pdo = IntegrationTestHelpers::connectToDatabase($type, $this->databaseServer, $this->restoredDatabaseName);
    expect($pdo)->toBeInstanceOf(PDO::class);

    $verifyQuery = match ($type) {
        'mysql' => 'SHOW TABLES',
        'postgres' => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'",
    };
    $stmt = $pdo->query($verifyQuery);
    expect($stmt)->not->toBeFalse();
})->with([
    'mysql with gzip' => ['mysql', 'gzip', 'gz'],
    'mysql with zstd' => ['mysql', 'zstd', 'zst'],
    'postgres with gzip' => ['postgres', 'gzip', 'gz'],
]);

test('sqlite backup and restore workflow', function () {
    // Create a test SQLite database with some data (use unique names for parallel testing)
    $backupDir = config('backup.working_directory');
    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $sourceSqlitePath = "{$backupDir}/test_source{$suffix}.sqlite";
    $restoredSqlitePath = "{$backupDir}/test_restored_".hrtime(true)."{$suffix}.sqlite";
    IntegrationTestHelpers::createTestSqliteDatabase($sourceSqlitePath);

    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('sqlite');
    $this->databaseServer = IntegrationTestHelpers::createSqliteDatabaseServer($sourceSqlitePath);
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backup.volume');

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        server: $this->databaseServer,
        method: 'manual',
        triggeredByUserId: null
    );
    $this->snapshot = $snapshots[0];
    $this->backupTask->run($this->snapshot);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->volume);

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->getDatabaseServerMetadata()['host'])->toBeNull()
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    // Create a target server for restore (different sqlite file)
    $targetServer = IntegrationTestHelpers::createSqliteDatabaseServer($restoredSqlitePath);
    Backup::create([
        'database_server_id' => $targetServer->id,
        'volume_id' => $this->volume->id,
        'recurrence' => 'manual',
    ]);

    // Run restore
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $targetServer,
        schemaName: $restoredSqlitePath,
    );
    $this->restoreTask->run($restore);

    // Verify restore - check that the restored database has the test data
    $pdo = new PDO("sqlite:{$restoredSqlitePath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query('SELECT COUNT(*) as count FROM test_table');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    expect((int) $result['count'])->toBe(3);

    $targetServer->delete();
});
