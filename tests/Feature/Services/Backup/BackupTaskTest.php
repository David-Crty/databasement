<?php

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\CompressorInterface;
use App\Services\Backup\DatabaseListService;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\GzipCompressor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\Filesystem;
use Tests\Support\TestShellProcessor;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use REAL services for command building
    $this->mysqlDatabase = new MysqlDatabase;  // ✓ Real command building
    $this->postgresqlDatabase = new PostgresqlDatabase;  // ✓ Real command building
    $this->shellProcessor = new TestShellProcessor;  // ✓ Captures commands without executing
    /** @var CompressorInterface $compressor */
    $this->compressor = new GzipCompressor($this->shellProcessor, 6);  // ✓ Real path manipulation

    // Mock external dependencies only
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);

    // Create the BackupTask instance
    $this->backupTask = new BackupTask(
        $this->mysqlDatabase,
        $this->postgresqlDatabase,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressor
    );

    // Use real BackupJobFactory from container
    $this->backupJobFactory = app(BackupJobFactory::class);

    // Create temp directory for test files and set config
    $this->tempDir = sys_get_temp_dir().'/backup-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    config(['backup.working_directory' => $this->tempDir]);
});

afterEach(function () {
    Mockery::close();
});

// Helper function to create a database server with backup and volume
function createDatabaseServer(array $attributes, string $volumeType = 'local'): DatabaseServer
{
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => $volumeType,
        'config' => ['root' => test()->tempDir],
    ]);

    // Create the database server first without backup
    $databaseServer = DatabaseServer::create($attributes);

    // Now create the backup with both volume_id and database_server_id
    $backup = Backup::create([
        'recurrence' => 'daily',
        'volume_id' => $volume->id,
        'database_server_id' => $databaseServer->id,
    ]);

    // Update the database server with the backup_id
    $databaseServer->update(['backup_id' => $backup->id]);

    // Reload with relationships
    $databaseServer->load('backup.volume');

    return $databaseServer;
}

// Helper function to set up common expectations
function setupCommonExpectations(Snapshot $snapshot): void
{
    $filesystem = Mockery::mock(Filesystem::class);

    // Filesystem provider
    test()->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->andReturn(test()->tempDir);

    test()->filesystemProvider
        ->shouldReceive('get')
        ->with($snapshot->databaseServer->backup->volume->type)
        ->andReturn($filesystem);

    test()->filesystemProvider
        ->shouldReceive('transfer')
        ->once();
}

test('run executes mysql and mariadb backup workflow successfully', function (string $cliType, string $expectedBinary, string $extraFlags) {
    // Set config - MysqlDatabase reads it lazily
    config(['backup.mysql_cli_type' => $cliType]);

    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'Production MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    setupCommonExpectations($snapshot);
    $this->backupTask->run($snapshot);

    // Build expected file paths
    $workingDir = $this->tempDir.'/backup-'.$snapshot->id;
    $sqlFile = $workingDir.'/dump.sql';

    $expectedCommands = [
        "{$expectedBinary} --single-transaction --routines --add-drop-table --complete-insert --hex-blob --quote-names {$extraFlags}--host='localhost' --port='3306' --user='root' --password='secret' 'myapp' > '$sqlFile'",
        "gzip -6 '$sqlFile'",
    ];
    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
})->with([
    'mariadb cli' => ['mariadb', 'mariadb-dump', '--skip_ssl '],
    'mysql cli' => ['mysql', 'mysqldump', ''],
]);

test('run executes postgresql backup workflow successfully', function () {
    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'Staging PostgreSQL',
        'host' => 'db.example.com',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'postgres',
        'password' => 'pg_secret',
        'database_names' => ['staging_db'],
    ], 's3');

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    setupCommonExpectations($snapshot);
    $this->backupTask->run($snapshot);

    // Build expected file paths
    $workingDir = $this->tempDir.'/backup-'.$snapshot->id;
    $sqlFile = $workingDir.'/dump.sql';

    $expectedCommands = [
        "PGPASSWORD='pg_secret' pg_dump --clean --if-exists --no-owner --no-privileges --quote-all-identifiers --host='db.example.com' --port='5432' --username='postgres' 'staging_db' -f '$sqlFile'",
        "gzip -6 '$sqlFile'",
    ];
    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
});

test('run throws exception for unsupported database type', function () {
    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'Oracle DB',
        'host' => 'localhost',
        'port' => 1521,
        'database_type' => 'oracle',
        'username' => 'system',
        'password' => 'oracle',
        'database_names' => ['orcl'],
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    // Act & Assert
    expect(fn () => $this->backupTask->run($snapshot))
        ->toThrow(\Exception::class, 'Database type oracle not supported');
});

test('run throws exception when backup command failed', function () {
    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'Production MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    // Create a shell processor that fails on dump command
    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    // Create BackupTask with mocked shell processor
    $backupTask = new BackupTask(
        $this->mysqlDatabase,
        $this->postgresqlDatabase,
        $shellProcessor,
        $this->filesystemProvider,
        $this->compressor
    );

    // Act & Assert
    $exception = null;
    try {
        $backupTask->run($snapshot);
    } catch (\App\Exceptions\ShellProcessFailed $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toBe('Access denied for user');

    // Verify the job status is set to failed
    $snapshot->refresh();
    $job = $snapshot->job;

    expect($job)->not->toBeNull();
    expect($job->status)->toBe('failed');
    expect($job->error_message)->toBe('Access denied for user');
    expect($job->completed_at)->not->toBeNull();
});

test('createSnapshots creates multiple snapshots when backup_all_databases is enabled', function () {
    // Arrange - Mock DatabaseListService to return multiple databases
    $mockDatabaseListService = Mockery::mock(DatabaseListService::class);
    $mockDatabaseListService->shouldReceive('listDatabases')
        ->once()
        ->andReturn(['app_db', 'analytics_db', 'logs_db']);

    // Create BackupJobFactory with mocked service
    $backupJobFactory = new BackupJobFactory($mockDatabaseListService);

    $databaseServer = createDatabaseServer([
        'name' => 'Multi-DB MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => null, // Not used when backup_all_databases is true
        'backup_all_databases' => true,
    ]);

    // Act
    $snapshots = $backupJobFactory->createSnapshots($databaseServer, 'manual');

    // Assert - Should create 3 snapshots, one for each database
    expect($snapshots)->toHaveCount(3)
        ->and($snapshots[0]->database_name)->toBe('app_db')
        ->and($snapshots[1]->database_name)->toBe('analytics_db')
        ->and($snapshots[2]->database_name)->toBe('logs_db');

    // All snapshots should share the same server but have independent jobs
    foreach ($snapshots as $snapshot) {
        expect($snapshot->database_server_id)->toBe($databaseServer->id)
            ->and($snapshot->database_type)->toBe('mysql')
            ->and($snapshot->compression_type)->toBe(config('backup.compression'))
            ->and($snapshot->job)->not->toBeNull()
            ->and($snapshot->job->status)->toBe('pending');
    }

    // Each snapshot should have a unique job
    $jobIds = array_map(fn ($s) => $s->job->id, $snapshots);
    expect(array_unique($jobIds))->toHaveCount(3);
});

test('run executes backup for each database when backup_all_databases is enabled', function () {
    // Arrange - Mock DatabaseListService
    $mockDatabaseListService = Mockery::mock(DatabaseListService::class);
    $mockDatabaseListService->shouldReceive('listDatabases')
        ->once()
        ->andReturn(['app_db', 'users_db']);

    $backupJobFactory = new BackupJobFactory($mockDatabaseListService);

    $databaseServer = createDatabaseServer([
        'name' => 'Multi-DB Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => null,
        'backup_all_databases' => true,
    ]);

    $snapshots = $backupJobFactory->createSnapshots($databaseServer, 'scheduled');

    // Setup expectations for each snapshot and run backup
    foreach ($snapshots as $snapshot) {
        $this->shellProcessor->clearCommands();
        setupCommonExpectations($snapshot);
        $this->backupTask->run($snapshot);
    }

    // Verify both snapshots were processed
    expect($snapshots)->toHaveCount(2);

    foreach ($snapshots as $snapshot) {
        $snapshot->refresh();
        expect($snapshot->job->status)->toBe('completed')
            ->and($snapshot->filename)->not->toBeEmpty()
            ->and($snapshot->file_size)->toBeGreaterThan(0);
    }
});

test('createSnapshots throws exception when backup_all_databases is enabled but no databases found', function () {
    // Arrange - Mock DatabaseListService to return empty list
    $mockDatabaseListService = Mockery::mock(DatabaseListService::class);
    $mockDatabaseListService->shouldReceive('listDatabases')
        ->once()
        ->andReturn([]);

    $backupJobFactory = new BackupJobFactory($mockDatabaseListService);

    $databaseServer = createDatabaseServer([
        'name' => 'Empty Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => null,
        'backup_all_databases' => true,
    ]);

    // Act & Assert
    expect(fn () => $backupJobFactory->createSnapshots($databaseServer, 'manual'))
        ->toThrow(\RuntimeException::class, 'No databases found on the server to backup.');
});

test('run handles backup path configuration correctly', function (?string $configuredPath, string $expectedPrefix) {
    $databaseServer = createDatabaseServer([
        'name' => 'MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);

    if ($configuredPath !== null) {
        $databaseServer->backup->update(['path' => $configuredPath]);
    }

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    setupCommonExpectations($snapshot);
    $this->backupTask->run($snapshot);

    $snapshot->refresh();

    if ($expectedPrefix === '') {
        expect($snapshot->filename)->not->toContain('/');
    } else {
        expect($snapshot->filename)->toStartWith($expectedPrefix);
    }
})->with([
    'no path configured' => [null, ''],
    'nested path' => ['mysql/production', 'mysql/production/'],
    'path with slashes trimmed' => ['/mysql/prod/', 'mysql/prod/'],
]);

test('run executes sqlite backup workflow successfully', function () {
    // Create a temporary SQLite file for testing
    $sqlitePath = $this->tempDir.'/test.sqlite';
    touch($sqlitePath);
    file_put_contents($sqlitePath, 'test sqlite content');

    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'SQLite Database',
        'database_type' => 'sqlite',
        'sqlite_path' => $sqlitePath,
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_names' => null,
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    // Verify snapshot has correct database name (filename)
    expect($snapshot->database_name)->toBe('test.sqlite');

    setupCommonExpectations($snapshot);
    $this->backupTask->run($snapshot);

    // Build expected file paths
    $workingDir = $this->tempDir.'/backup-'.$snapshot->id;
    $dbFile = $workingDir.'/dump.db';

    $expectedCommands = [
        "cp '{$sqlitePath}' '{$dbFile}'",
        "gzip -6 '{$dbFile}'",
    ];
    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);

    // Verify snapshot is completed
    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('completed')
        ->and($snapshot->filename)->toContain('.db.gz');
});
