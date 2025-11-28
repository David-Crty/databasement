<?php

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\GzipCompressor;
use App\Services\Backup\RestoreTask;
use App\Services\DatabaseConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestShellProcessor;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use REAL services for command building
    $this->mysqlDatabase = new MysqlDatabase;  // ✓ Real command building
    $this->postgresqlDatabase = new PostgresqlDatabase;  // ✓ Real command building
    $this->compressor = new GzipCompressor;  // ✓ Real path manipulation
    $this->shellProcessor = new TestShellProcessor;  // ✓ Captures commands without executing

    // Mock external dependencies only
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->connectionTester = Mockery::mock(DatabaseConnectionTester::class);

    // Create a partial mock of RestoreTask to mock prepareDatabase
    $this->restoreTask = Mockery::mock(
        RestoreTask::class,
        [
            $this->mysqlDatabase,
            $this->postgresqlDatabase,
            $this->shellProcessor,
            $this->filesystemProvider,
            $this->compressor,
            $this->connectionTester,
        ]
    )->makePartial()
        ->shouldAllowMockingProtectedMethods();

    // Create temp directory for test files
    $this->tempDir = sys_get_temp_dir().'/restore-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
});

// Helper function to create a database server for restore tests
function createRestoreDatabaseServer(array $attributes): DatabaseServer
{
    return DatabaseServer::create($attributes);
}

// Helper function to create a snapshot for restore tests
function createRestoreSnapshot(DatabaseServer $databaseServer, array $attributes = []): Snapshot
{
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['root' => test()->tempDir],
    ]);

    $backup = Backup::create([
        'recurrence' => 'daily',
        'volume_id' => $volume->id,
        'database_server_id' => $databaseServer->id,
    ]);

    return Snapshot::create(array_merge([
        'database_server_id' => $databaseServer->id,
        'backup_id' => $backup->id,
        'volume_id' => $volume->id,
        'path' => 'test-backup.sql.gz',
        'file_size' => 1024,
        'started_at' => now(),
        'completed_at' => now(),
        'status' => 'completed',
        'database_name' => $databaseServer->database_name ?? 'testdb',
        'database_type' => $databaseServer->database_type,
        'database_host' => $databaseServer->host,
        'database_port' => $databaseServer->port,
        'compression_type' => 'gzip',
        'method' => 'manual',
    ], $attributes));
}

// Helper function to set up common expectations for restore
function setupRestoreExpectations(
    DatabaseServer $targetServer,
    Snapshot $snapshot,
    string $schemaName
): void {
    // Connection test
    test()->connectionTester
        ->shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected']);

    // Mock prepareDatabase to avoid real database operations
    test()->restoreTask
        ->shouldReceive('prepareDatabase')
        ->once()
        ->with($targetServer, $schemaName, Mockery::any())
        ->andReturnNull();

    // Mock download - create a compressed file that will be decompressed
    test()->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->with($snapshot, Mockery::any())
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });
}

afterEach(function () {
    // Remove temp directory and all files within
    if (is_dir($this->tempDir)) {
        // Remove any remaining files in the directory
        $files = glob($this->tempDir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    Mockery::close();
});

test('run executes mysql restore workflow successfully', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'sourcedb',
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'target.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'targetdb',
    ]);

    // Create snapshot with a known path
    $snapshot = createRestoreSnapshot($sourceServer, ['path' => 'backup.sql.gz']);

    setupRestoreExpectations($targetServer, $snapshot, 'restored_db');

    // Act
    $this->restoreTask->run($targetServer, $snapshot, 'restored_db', $this->tempDir);

    // Build expected file paths
    $compressedFile = $this->tempDir.'/backup.sql.gz';
    $tempCompressed = $compressedFile.'.tmp.gz';
    $decompressedFile = $this->tempDir.'/backup.sql.gz.tmp';

    // Expected commands
    $expectedCommands = [
        "gzip -d '$tempCompressed'",
        "mariadb --host='target.localhost' --port='3306' --user='root' --password='secret' --skip_ssl 'restored_db' -e \"source $decompressedFile\"",
    ];

    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
});

test('run executes postgresql restore workflow successfully', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source PostgreSQL',
        'host' => 'source.localhost',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'postgres',
        'password' => 'secret',
        'database_name' => 'sourcedb',
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target PostgreSQL',
        'host' => 'target.localhost',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'postgres',
        'password' => 'secret',
        'database_name' => 'targetdb',
    ]);

    // Create snapshot with a known path
    $snapshot = createRestoreSnapshot($sourceServer, [
        'database_type' => 'postgresql',
        'path' => 'pg_backup.sql.gz',
    ]);

    setupRestoreExpectations($targetServer, $snapshot, 'restored_db');

    // Act
    $this->restoreTask->run($targetServer, $snapshot, 'restored_db', $this->tempDir);

    // Build expected file paths
    $compressedFile = $this->tempDir.'/pg_backup.sql.gz';
    $tempCompressed = $compressedFile.'.tmp.gz';
    $decompressedFile = $this->tempDir.'/pg_backup.sql.gz.tmp';

    // Expected commands (PostgreSQL uses escapeshellarg on paths, adding quotes)
    $expectedCommands = [
        "gzip -d '$tempCompressed'",
        "PGPASSWORD='secret' psql --host='target.localhost' --port='5432' --user='postgres' 'restored_db' -f '$decompressedFile'",
    ];

    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
});

test('run throws exception when database types are incompatible', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'sourcedb',
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target PostgreSQL',
        'host' => 'localhost',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'postgres',
        'password' => 'secret',
        'database_name' => 'targetdb',
    ]);

    $snapshot = createRestoreSnapshot($sourceServer);

    // Act & Assert
    expect(fn () => $this->restoreTask->run($targetServer, $snapshot, 'restored_db'))
        ->toThrow(\Exception::class, 'Cannot restore mysql snapshot to postgresql server');
});

test('run throws exception when connection test fails', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'sourcedb',
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'wrongpassword',
        'database_name' => 'targetdb',
    ]);

    $snapshot = createRestoreSnapshot($sourceServer);

    // Connection test fails
    $this->connectionTester
        ->shouldReceive('test')
        ->once()
        ->andReturn(['success' => false, 'message' => 'Access denied']);

    // Act & Assert
    expect(fn () => $this->restoreTask->run($targetServer, $snapshot, 'restored_db'))
        ->toThrow(\Exception::class, 'Failed to connect to target server: Access denied');
});

test('run throws exception for unsupported database type', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source Oracle',
        'host' => 'localhost',
        'port' => 1521,
        'database_type' => 'oracle',
        'username' => 'system',
        'password' => 'secret',
        'database_name' => 'sourcedb',
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target Oracle',
        'host' => 'localhost',
        'port' => 1521,
        'database_type' => 'oracle',
        'username' => 'system',
        'password' => 'secret',
        'database_name' => 'targetdb',
    ]);

    $snapshot = createRestoreSnapshot($sourceServer, ['database_type' => 'oracle']);

    // Connection test succeeds but database type is not supported
    $this->connectionTester
        ->shouldReceive('test')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connected']);

    $this->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed test data');
        });

    // Act & Assert
    expect(fn () => $this->restoreTask->run($targetServer, $snapshot, 'restored_db', $this->tempDir))
        ->toThrow(\Exception::class, 'Database type oracle not supported');
});
