<?php

/**
 * Integration tests for backup and restore with real databases.
 *
 * These tests require MySQL and PostgreSQL containers to be running.
 * Run with: php artisan test --group=integration
 * Or: make backup-test
 */

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\RestoreTask;

uses()->group('integration');

beforeEach(function () {
    $this->backupTask = app(BackupTask::class);
    $this->restoreTask = app(RestoreTask::class);
    $this->backupJobFactory = app(BackupJobFactory::class);

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
            integrationDropRestoredDatabase(
                $this->databaseServer->database_type === 'postgresql' ? 'postgres' : 'mysql',
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

test('mysql backup and restore workflow', function () {
    integrationSetupTestEnvironment();
    integrationCleanupLeftoverTestData('mysql');

    // Create models
    $this->volume = integrationCreateVolume('mysql');
    $this->databaseServer = integrationCreateDatabaseServer('mysql');
    $this->backup = integrationCreateBackup($this->databaseServer, $this->volume);
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

    expect($this->snapshot->job->status)->toBe('completed');
    expect($this->snapshot->file_size)->toBeGreaterThan(0);

    // Verify backup file
    $backupFile = integrationFindLatestBackupFile();
    expect($backupFile)->not->toBeNull();
    expect(filesize($backupFile))->toBeGreaterThan(100);
    expect(integrationIsGzipped($backupFile))->toBeTrue();

    // Run restore
    $this->restoredDatabaseName = 'testdb_restored_'.time();
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    $this->restoreTask->run($restore);

    // Verify restore
    $pdo = integrationConnectToDatabase('mysql', $this->databaseServer, $this->restoredDatabaseName);
    expect($pdo)->toBeInstanceOf(PDO::class);

    $stmt = $pdo->query('SHOW TABLES');
    expect($stmt)->not->toBeFalse();
});

test('postgresql backup and restore workflow', function () {
    integrationSetupTestEnvironment();
    integrationCleanupLeftoverTestData('postgres');

    // Create models
    $this->volume = integrationCreateVolume('postgres');
    $this->databaseServer = integrationCreateDatabaseServer('postgres');
    $this->backup = integrationCreateBackup($this->databaseServer, $this->volume);
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

    expect($this->snapshot->job->status)->toBe('completed');
    expect($this->snapshot->file_size)->toBeGreaterThan(0);

    // Verify backup file
    $backupFile = integrationFindLatestBackupFile();
    expect($backupFile)->not->toBeNull();
    expect(filesize($backupFile))->toBeGreaterThan(100);
    expect(integrationIsGzipped($backupFile))->toBeTrue();

    // Run restore
    $this->restoredDatabaseName = 'testdb_restored_'.time();
    $restore = $this->backupJobFactory->createRestore(
        snapshot: $this->snapshot,
        targetServer: $this->databaseServer,
        schemaName: $this->restoredDatabaseName,
    );
    $this->restoreTask->run($restore);

    // Verify restore
    $pdo = integrationConnectToDatabase('postgres', $this->databaseServer, $this->restoredDatabaseName);
    expect($pdo)->toBeInstanceOf(PDO::class);

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
    expect($stmt)->not->toBeFalse();
});

// Helper functions

function integrationSetupTestEnvironment(): void
{
    $backupDir = '/tmp/backups';
    if (! is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
}

function integrationCreateVolume(string $type): Volume
{
    return Volume::create([
        'name' => "Integration Test Volume ({$type})",
        'type' => 'local',
        'config' => ['root' => '/tmp/backups'],
    ]);
}

function integrationCreateDatabaseServer(string $type): DatabaseServer
{
    $config = match ($type) {
        'mysql' => [
            'name' => 'Integration Test MySQL Server',
            'host' => config('testing.databases.mysql.host'),
            'port' => config('testing.databases.mysql.port'),
            'database_type' => 'mysql',
            'username' => config('testing.databases.mysql.username'),
            'password' => config('testing.databases.mysql.password'),
            'database_names' => [config('testing.databases.mysql.database')],
            'description' => 'Integration test MySQL database server',
        ],
        'postgres' => [
            'name' => 'Integration Test PostgreSQL Server',
            'host' => config('testing.databases.postgres.host'),
            'port' => config('testing.databases.postgres.port'),
            'database_type' => 'postgresql',
            'username' => config('testing.databases.postgres.username'),
            'password' => config('testing.databases.postgres.password'),
            'database_names' => [config('testing.databases.postgres.database')],
            'description' => 'Integration test PostgreSQL database server',
        ],
        default => throw new InvalidArgumentException("Unsupported database type: {$type}"),
    };

    return DatabaseServer::create($config);
}

function integrationCreateBackup(DatabaseServer $server, Volume $volume): Backup
{
    return Backup::create([
        'database_server_id' => $server->id,
        'volume_id' => $volume->id,
        'recurrence' => 'manual',
    ]);
}

function integrationCleanupLeftoverTestData(string $type): void
{
    $serverName = match ($type) {
        'mysql' => 'Integration Test MySQL Server',
        'postgres' => 'Integration Test PostgreSQL Server',
        default => null,
    };

    if ($serverName) {
        DatabaseServer::where('name', $serverName)->first()?->delete();
    }

    Volume::where('name', "Integration Test Volume ({$type})")->first()?->delete();
}

function integrationFindLatestBackupFile(): ?string
{
    $files = glob('/tmp/backups/*.sql.gz');
    if (empty($files)) {
        return null;
    }

    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

    return $files[0];
}

function integrationIsGzipped(string $filePath): bool
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return false;
    }

    $header = fread($handle, 2);
    fclose($handle);

    return $header !== false && bin2hex($header) === '1f8b';
}

function integrationConnectToDatabase(string $type, DatabaseServer $server, string $databaseName): PDO
{
    $dsn = match ($type) {
        'mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s', $server->host, $server->port, $databaseName),
        'postgres' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $server->host, $server->port, $databaseName),
        default => throw new InvalidArgumentException("Unsupported database type: {$type}"),
    };

    return new PDO($dsn, $server->username, $server->password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function integrationDropRestoredDatabase(string $type, DatabaseServer $server, string $databaseName): void
{
    $dsn = match ($type) {
        'mysql' => sprintf('mysql:host=%s;port=%d', $server->host, $server->port),
        'postgres' => sprintf('pgsql:host=%s;port=%d;dbname=postgres', $server->host, $server->port),
        default => throw new InvalidArgumentException("Unsupported database type: {$type}"),
    };

    $pdo = new PDO($dsn, $server->username, $server->password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    if ($type === 'mysql') {
        $pdo->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
    } elseif ($type === 'postgres') {
        $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$databaseName}' AND pid <> pg_backend_pid()");
        $pdo->exec("DROP DATABASE IF EXISTS \"{$databaseName}\"");
    }
}
