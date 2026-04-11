<?php

/**
 * Integration tests for backup and restore with real databases.
 *
 * These tests require MySQL and PostgreSQL containers to be running.
 * Run with: php artisan test --group=integration
 */

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Models\Backup;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\RestoreTask;
use Tests\Support\IntegrationTestHelpers;

beforeEach(function () {
    $this->backupJobFactory = app(BackupJobFactory::class);
    $this->filesystemProvider = app(FilesystemProvider::class);

    $this->volume = null;
    $this->databaseServer = null;
    $this->backup = null;
    $this->snapshot = null;
    $this->restoredDatabaseName = null;
});

afterEach(function () {
    // Cleanup restored database on the external database server
    if ($this->restoredDatabaseName && $this->databaseServer) {
        try {
            IntegrationTestHelpers::dropDatabase(
                $this->databaseServer->database_type->value,
                $this->databaseServer,
                $this->restoredDatabaseName
            );
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }
});

test('client-server database backup and restore workflow', function (string $type, string $compression, string $expectedExt) {
    // Set compression method (and encryption key for encrypted backups)
    AppConfig::set('backup.compression', $compression);
    if ($compression === 'encrypted') {
        config(['backup.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    }

    // Clear singleton bindings and recreate tasks with new compression config
    app()->forgetInstance(CompressorInterface::class);
    app()->forgetInstance(BackupTask::class);
    app()->forgetInstance(RestoreTask::class);

    // Create models
    $this->volume = IntegrationTestHelpers::createVolume($type);
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer($type);
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volume');

    // Load test data
    IntegrationTestHelpers::loadTestData($type, $this->databaseServer);

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->volume);

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->compression_type)->toBe(CompressionType::from($compression))
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
    ProcessRestoreJob::dispatchSync($restore->id);

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
    'postgres with gzip' => ['postgres', 'gzip', 'gz'],
    'mysql with zstd' => ['mysql', 'zstd', 'zst'],
    'mysql with encrypted' => ['mysql', 'encrypted', '7z'],
]);

test('backup with extra dump flags succeeds', function (string $type, string $flag) {
    // Create models with dump flags in extra_config
    $this->volume = IntegrationTestHelpers::createVolume($type);
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer($type);
    $this->databaseServer->update([
        'extra_config' => array_merge(
            $this->databaseServer->extra_config ?? [],
            ['dump_flags' => $flag],
        ),
    ]);
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volume');

    // Load test data
    IntegrationTestHelpers::loadTestData($type, $this->databaseServer);

    // Run backup — this would fail if flags are mispositioned (e.g., after the database name)
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0);
})->with([
    'mysql with --verbose' => ['mysql', '--verbose'],
    'postgres with --verbose' => ['postgres', '--verbose'],
    'mongodb with --verbose' => ['mongodb', '--verbose'],
]);

test('sqlite backup and restore workflow', function () {
    // Create a test SQLite database with some data (use unique names for parallel testing)
    $backupDir = AppConfig::get('backup.working_directory');
    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $sourceSqlitePath = "{$backupDir}/test_source{$suffix}.sqlite";
    $restoredSqlitePath = "{$backupDir}/test_restored_".hrtime(true)."{$suffix}.sqlite";
    IntegrationTestHelpers::createTestSqliteDatabase($sourceSqlitePath);

    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('sqlite');
    $this->databaseServer = IntegrationTestHelpers::createSqliteDatabaseServer($sourceSqlitePath);
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volume');

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
        triggeredByUserId: null
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->volume);

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->getDatabaseServerMetadata()['host'])->toBeNull()
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    // Create a target server for restore (different sqlite file)
    $targetServer = IntegrationTestHelpers::createSqliteDatabaseServer($restoredSqlitePath);
    $schedule = dailySchedule();
    Backup::create([
        'database_server_id' => $targetServer->id,
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $schedule->id,
    ]);

    // Run restore
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $targetServer,
        schemaName: $restoredSqlitePath,
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    // Verify restore - check that the restored database has the test data
    $pdo = new PDO("sqlite:{$restoredSqlitePath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query('SELECT COUNT(*) as count FROM test_table');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    expect((int) $result['count'])->toBe(3);
});

test('mongodb backup and restore workflow', function () {
    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('mongodb');
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer('mongodb');
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volume');

    // Load test data
    IntegrationTestHelpers::loadMongodbTestData($this->databaseServer);

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->volume);

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->filename)->toEndWith('.archive.gz')
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    // Run restore (use unique name with parallel token and microseconds to avoid collisions)
    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $this->restoredDatabaseName = 'testdb_restored_'.hrtime(true).$suffix;
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    // Verify restore — check that collections exist in the restored database
    $collectionCount = IntegrationTestHelpers::verifyMongodbRestore($this->databaseServer, $this->restoredDatabaseName);
    expect($collectionCount)->toBeGreaterThanOrEqual(2);
});

test('redis backup workflow', function () {
    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('redis');
    $this->databaseServer = IntegrationTestHelpers::createRedisDatabaseServer();
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volume');

    // Load test data
    IntegrationTestHelpers::loadRedisTestData($this->databaseServer);

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->volume);

    expect($this->snapshot->job->status)->toBe('completed')
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->database_name)->toBe('all')
        ->and($this->snapshot->filename)->toEndWith('.rdb.gz')
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();
});
