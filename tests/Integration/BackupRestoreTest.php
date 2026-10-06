<?php

/**
 * Integration tests for backup and restore with real databases.
 *
 * These tests require MySQL and PostgreSQL containers to be running.
 * Run with: php artisan test --group=integration
 */

use App\Enums\BackupJobStatus;
use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\RestoreTask;
use Tests\Support\IntegrationTestHelpers;
use Tests\TestCase;

beforeEach(function () {
    $this->backupJobFactory = app(BackupJobFactory::class);
    $this->filesystemProvider = app(FilesystemProvider::class);

    $this->volume = null;
    $this->databaseServer = null;
    $this->backup = null;
    $this->snapshot = null;
    $this->restoredDatabaseName = null;
    $this->postgresRoles = [];
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

    foreach ($this->postgresRoles as $role) {
        try {
            DatabaseType::POSTGRESQL->createPdo($this->databaseServer)->exec("DROP ROLE IF EXISTS \"{$role}\"");
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }
});

/**
 * Back up the Postgres fixture data as the integration server's own user,
 * its bootstrap superuser, leaving the completed snapshot in $this->snapshot.
 */
function backUpPostgresFixture(TestCase $test, ?string $dumpFormat): void
{
    AppConfig::set('backup.compression', 'gzip');

    app()->forgetInstance(CompressorInterface::class);
    app()->forgetInstance(BackupTask::class);
    app()->forgetInstance(RestoreTask::class);

    $test->volume = IntegrationTestHelpers::createVolume('postgres');
    $test->databaseServer = IntegrationTestHelpers::createDatabaseServer('postgres');
    if ($dumpFormat !== null) {
        $test->databaseServer->update([
            'extra_config' => array_merge($test->databaseServer->extra_config ?? [], ['dump_format' => $dumpFormat]),
        ]);
    }
    $test->backup = IntegrationTestHelpers::createBackup($test->databaseServer, $test->volume);
    $test->databaseServer->load('backups.volumes');

    IntegrationTestHelpers::loadTestData('postgres', $test->databaseServer);

    $test->snapshot = $test->backupJobFactory->createSnapshots(backup: $test->backup, method: 'manual')[0];
    ProcessBackupJob::dispatchSync($test->snapshot->id);
    $test->snapshot->refresh();
}

/** Create a role as $creator's user, dropped again after the test. */
function createPostgresRole(TestCase $test, DatabaseServer $creator, string $prefix, string $attributes): string
{
    $role = $prefix.'_'.hrtime(true).IntegrationTestHelpers::getParallelSuffix();

    DatabaseType::POSTGRESQL->createPdo($creator)->exec("CREATE ROLE \"{$role}\" {$attributes}");
    array_unshift($test->postgresRoles, $role);

    return $role;
}

function restorePostgresSnapshotAsOwner(TestCase $test, DatabaseServer $target, string $owner): Restore
{
    $test->restoredDatabaseName ??= 'testdb_restored_'.hrtime(true).IntegrationTestHelpers::getParallelSuffix();

    $restore = $test->backupJobFactory->createRestore(
        snapshot: $test->snapshot,
        targetServer: $target,
        schemaName: $test->restoredDatabaseName,
        options: ['owner_user' => $owner],
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    return $restore->refresh();
}

/**
 * @return array{database: string, tables: list<string>}
 */
function restoredPostgresOwners(TestCase $test): array
{
    $pdo = IntegrationTestHelpers::connectToDatabase('postgres', $test->databaseServer, $test->restoredDatabaseName);

    return [
        'database' => $pdo->query('SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()')->fetchColumn(),
        'tables' => $pdo->query("SELECT DISTINCT tableowner FROM pg_tables WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_COLUMN),
    ];
}

test('mysql backup and restore workflow', function (string $target, string $compression, string $expectedExt) {
    AppConfig::set('backup.compression', $compression);
    if ($compression === 'encrypted') {
        config(['backup.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    }

    // Clear singleton bindings and recreate tasks with new compression config
    app()->forgetInstance(CompressorInterface::class);
    app()->forgetInstance(BackupTask::class);
    app()->forgetInstance(RestoreTask::class);

    $this->volume = IntegrationTestHelpers::createVolume($target);
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer($target);
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volumes');

    IntegrationTestHelpers::loadTestData($target, $this->databaseServer);

    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->compression_type)->toBe(CompressionType::from($compression))
        ->and($this->snapshot->filename)->toEndWith(".sql.{$expectedExt}")
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $this->restoredDatabaseName = 'testdb_restored_'.hrtime(true).$suffix;
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    $pdo = IntegrationTestHelpers::connectToDatabase($target, $this->databaseServer, $this->restoredDatabaseName);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('users')->toContain('products')
        ->and((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(2)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn())->toBe(2);
})->with([
    // Both engines, because they are dumped and restored by different clients.
    'mysql, zstd' => ['mysql', 'zstd', 'zst'],
    'mysql, encrypted' => ['mysql', 'encrypted', '7z'],
    'mariadb, zstd' => ['mariadb', 'zstd', 'zst'],
]);

test('postgres backup and restore workflow', function (?string $dumpFormat) {
    AppConfig::set('backup.compression', 'gzip');

    app()->forgetInstance(CompressorInterface::class);
    app()->forgetInstance(BackupTask::class);
    app()->forgetInstance(RestoreTask::class);

    $this->volume = IntegrationTestHelpers::createVolume('postgres');
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer('postgres');
    if ($dumpFormat !== null) {
        $this->databaseServer->update([
            'extra_config' => array_merge(
                $this->databaseServer->extra_config ?? [],
                ['dump_format' => $dumpFormat],
            ),
        ]);
    }
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volumes');

    IntegrationTestHelpers::loadTestData('postgres', $this->databaseServer);

    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    $expectedDumpExt = $dumpFormat === 'custom' ? 'dump' : 'sql';
    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->compression_type)->toBe(CompressionType::GZIP)
        ->and($this->snapshot->filename)->toEndWith(".{$expectedDumpExt}.gz")
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $this->restoredDatabaseName = 'testdb_restored_'.hrtime(true).$suffix;
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    $pdo = IntegrationTestHelpers::connectToDatabase('postgres', $this->databaseServer, $this->restoredDatabaseName);
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('users')->toContain('products')
        ->and((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(2)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn())->toBe(2);
})->with([
    'plain format' => [null],
    'custom dump format' => ['custom'],
]);

// The integration server connects as its bootstrap superuser, for which
// PostgreSQL refuses REASSIGN OWNED BY outright (#607).
test('postgres restore hands the database and its objects to the owner user', function (?string $dumpFormat) {
    backUpPostgresFixture($this, $dumpFormat);
    $owner = createPostgresRole($this, $this->databaseServer, 'restore_owner', 'LOGIN');

    $restore = restorePostgresSnapshotAsOwner($this, $this->databaseServer, $owner);

    expect($restore->job->status)->toBe(BackupJobStatus::Completed)
        ->and(restoredPostgresOwners($this))->toBe(['database' => $owner, 'tables' => [$owner]]);
})->with([
    'plain format' => [null],
    'custom dump format' => ['custom'],
]);

// Managed services (RDS, Azure, Cloud SQL) connect as a CREATEROLE user short
// of superuser, which from PostgreSQL 16 on is not a member of the roles it
// creates until it grants itself one.
test('postgres restore as a non-superuser needs membership in the owner role', function () {
    backUpPostgresFixture($this, null);
    $admin = createPostgresRole($this, $this->databaseServer, 'managed_admin', "LOGIN CREATEDB CREATEROLE PASSWORD 'managed_secret'");

    $target = $this->databaseServer->replicate();
    $target->fill(['name' => 'Managed-like Postgres', 'username' => $admin, 'password' => 'managed_secret'])->save();
    $owner = createPostgresRole($this, $target, 'restore_owner', 'LOGIN');

    $refused = rescue(fn () => restorePostgresSnapshotAsOwner($this, $target, $owner), report: false);
    expect($refused)->toBeNull()
        ->and(Restore::latest('created_at')->first()->job->error_message)
        ->toContain(sprintf('GRANT "%s" TO "%s";', $owner, $admin));

    DatabaseType::POSTGRESQL->createPdo($target)->exec(sprintf('GRANT "%s" TO "%s"', $owner, $admin));

    $restore = restorePostgresSnapshotAsOwner($this, $target, $owner);

    expect($restore->job->status)->toBe(BackupJobStatus::Completed)
        ->and(restoredPostgresOwners($this))->toBe(['database' => $owner, 'tables' => [$owner]]);
});

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
    $this->databaseServer->load('backups.volumes');

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

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($this->snapshot->file_size)->toBeGreaterThan(0);
})->with([
    'mysql with --verbose' => ['mysql', '--verbose'],
    'mariadb with --verbose' => ['mariadb', '--verbose'],
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
    $this->databaseServer->load('backups.volumes');

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

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
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
    $this->databaseServer->load('backups.volumes');

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

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
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

test('mssql backup and restore workflow', function () {
    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('mssql');
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer('mssql');
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volumes');

    // Load test data (resets the target database and runs the fixture script)
    IntegrationTestHelpers::loadTestData('mssql', $this->databaseServer);

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->filename)->toEndWith('.dacpac.gz')
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

    // Verify restore — the fixture inserts 2 users; sqlpackage Publish recreates schema + data.
    $pdo = IntegrationTestHelpers::connectToDatabase('mssql', $this->databaseServer, $this->restoredDatabaseName);
    $stmt = $pdo->query('SELECT COUNT(*) FROM dbo.users');
    expect($stmt)->not->toBeFalse()
        ->and((int) $stmt->fetchColumn())->toBe(2);
});

test('redis backup workflow', function () {
    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('redis');
    $this->databaseServer = IntegrationTestHelpers::createRedisDatabaseServer();
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volumes');

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

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->database_name)->toBe('all')
        ->and($this->snapshot->filename)->toEndWith('.rdb.gz')
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();
});

test('firebird backup and restore workflow', function () {
    // Create models
    $this->volume = IntegrationTestHelpers::createVolume('firebird');
    $this->databaseServer = IntegrationTestHelpers::createDatabaseServer('firebird');
    $this->backup = IntegrationTestHelpers::createBackup($this->databaseServer, $this->volume);
    $this->databaseServer->load('backups.volumes');

    // Load test data
    IntegrationTestHelpers::loadTestData('firebird', $this->databaseServer);

    // Run backup
    $snapshots = $this->backupJobFactory->createSnapshots(
        backup: $this->backup,
        method: 'manual',
    );
    $this->snapshot = $snapshots[0];
    ProcessBackupJob::dispatchSync($this->snapshot->id);
    $this->snapshot->refresh();
    $this->snapshot->load('job');

    $filesystem = $this->filesystemProvider->getForVolume($this->snapshot->files()->firstOrFail()->volume);

    expect($this->snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($this->snapshot->file_size)->toBeGreaterThan(0)
        ->and($this->snapshot->filename)->toEndWith('.fbk.gz')
        ->and($filesystem->fileExists($this->snapshot->filename))->toBeTrue();

    // Run restore (use unique name with parallel token and microseconds to avoid collisions)
    $suffix = IntegrationTestHelpers::getParallelSuffix();
    $this->restoredDatabaseName = '/var/lib/firebird/data/testdb_restored_'.hrtime(true).$suffix.'.fdb';
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    ProcessRestoreJob::dispatchSync($restore->id);

    // Verify restore — the fixture inserts 3 rows in test_table
    $rowCount = IntegrationTestHelpers::verifyFirebirdRestore($this->databaseServer, $this->restoredDatabaseName);
    expect($rowCount)->toBe(3);
});
