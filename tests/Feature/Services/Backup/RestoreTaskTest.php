<?php

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\GzipCompressor;
use App\Services\Backup\RestoreTask;
use App\Services\ConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestShellProcessor;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use REAL services for command building
    $this->mysqlDatabase = new MysqlDatabase;  // ✓ Real command building
    $this->postgresqlDatabase = new PostgresqlDatabase;  // ✓ Real command building
    $this->shellProcessor = new TestShellProcessor;  // ✓ Captures commands without executing
    $this->compressor = new GzipCompressor($this->shellProcessor);  // ✓ Real path manipulation

    // Mock external dependencies only
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->connectionFactory = Mockery::mock(ConnectionFactory::class);

    // Create a partial mock of RestoreTask to mock prepareDatabase
    $this->restoreTask = Mockery::mock(
        RestoreTask::class,
        [
            $this->mysqlDatabase,
            $this->postgresqlDatabase,
            $this->shellProcessor,
            $this->filesystemProvider,
            $this->compressor,
            $this->connectionFactory,
        ]
    )->makePartial()
        ->shouldAllowMockingProtectedMethods();

    // Use real BackupJobFactory from container
    $this->backupJobFactory = app(BackupJobFactory::class);

    // Create temp directory for test files and set it as the backup tmp folder
    $this->tempDir = sys_get_temp_dir().'/restore-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    config(['backup.working_directory' => $this->tempDir]);
});

afterEach(function () {
    Mockery::close();
});

// Helper function to create a database server with backup and volume for restore tests
function createRestoreDatabaseServer(array $attributes): DatabaseServer
{
    $volume = Volume::create([
        'name' => 'Test Volume '.uniqid(),
        'type' => 'local',
        'config' => ['root' => test()->tempDir],
    ]);

    $databaseServer = DatabaseServer::create($attributes);

    $backup = Backup::create([
        'recurrence' => 'daily',
        'volume_id' => $volume->id,
        'database_server_id' => $databaseServer->id,
    ]);

    $databaseServer->update(['backup_id' => $backup->id]);
    $databaseServer->load('backup.volume');

    return $databaseServer;
}

// Helper function to set up common expectations for restore
function setupRestoreExpectations(Restore $restore): void
{
    // Mock prepareDatabase to avoid real database operations
    test()->restoreTask
        ->shouldReceive('prepareDatabase')
        ->once()
        ->with($restore->targetServer, $restore->schema_name, Mockery::any())
        ->andReturnNull();

    // Mock download - create a compressed file that will be decompressed
    test()->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->with($restore->snapshot, Mockery::any())
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });
}

test('run executes mysql restore workflow successfully', function (string $cliType, string $expectedCommand) {
    // Set config - MysqlDatabase now reads it lazily
    config(['backup.mysql_cli_type' => $cliType]);

    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'target.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    // Create snapshot and update path for restore test
    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz']);
    $snapshot->job->markCompleted();

    // Create restore job
    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    setupRestoreExpectations($restore);

    // Act
    $this->restoreTask->run($restore);

    // Build expected file paths
    $workingDir = $this->tempDir.'/restore-'.$restore->id;
    $compressedFile = $workingDir.'/snapshot.gz';
    $decompressedFile = $workingDir.'/snapshot';

    // Expected commands
    $expectedCommands = [
        "gzip -d '$compressedFile'",
        "{$expectedCommand} 'restored_db' -e \"source $decompressedFile\"",
    ];

    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
})->with([
    'mariadb' => [
        'mariadb',
        "mariadb --host='target.localhost' --port='3306' --user='root' --password='secret' --skip_ssl",
    ],
    'mysql' => [
        'mysql',
        "mysql --host='target.localhost' --port='3306' --user='root' --password='secret'",
    ],
]);

test('run executes postgresql restore workflow successfully', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source PostgreSQL',
        'host' => 'source.localhost',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'postgres',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target PostgreSQL',
        'host' => 'target.localhost',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'postgres',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    // Create snapshot and update path for restore test
    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'pg_backup.sql.gz']);
    $snapshot->job->markCompleted();

    // Create restore job
    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    setupRestoreExpectations($restore);

    // Act
    $this->restoreTask->run($restore);

    // Build expected file paths
    $workingDir = $this->tempDir.'/restore-'.$restore->id;
    $compressedFile = $workingDir.'/snapshot.gz';
    $decompressedFile = $workingDir.'/snapshot';

    // Expected commands (PostgreSQL uses escapeshellarg on paths, adding quotes)
    $expectedCommands = [
        "gzip -d '$compressedFile'",
        "PGPASSWORD='secret' psql --host='target.localhost' --port='5432' --username='postgres' 'restored_db' -f '$decompressedFile'",
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
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target PostgreSQL',
        'host' => 'localhost',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'postgres',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    // Create snapshot and mark as completed
    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz']);
    $snapshot->job->markCompleted();

    // Create restore job
    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    // Act & Assert
    expect(fn () => $this->restoreTask->run($restore))
        ->toThrow(\App\Exceptions\Backup\RestoreException::class, 'Cannot restore mysql snapshot to postgres server');
});

test('run throws exception when restore command failed', function () {
    // Arrange
    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'target.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    // Create snapshot and mark as completed
    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz']);
    $snapshot->job->markCompleted();

    // Create restore job
    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    // Create a shell processor that fails on restore command (the second call after decompress)
    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    // Mock compressor to skip decompression and simulate decompressed file
    $compressor = Mockery::mock(\App\Services\Backup\GzipCompressor::class);
    $compressor->shouldReceive('decompress')
        ->once()
        ->andReturnUsing(function ($compressedFile) {
            $decompressedFile = preg_replace('/\.gz$/', '', $compressedFile);
            file_put_contents($decompressedFile, "-- Fake decompressed data\n");

            return $decompressedFile;
        });

    // Recreate RestoreTask with mocked shell processor and compressor
    $restoreTask = Mockery::mock(
        \App\Services\Backup\RestoreTask::class,
        [
            $this->mysqlDatabase,
            $this->postgresqlDatabase,
            $shellProcessor,
            $this->filesystemProvider,
            $compressor,
            $this->connectionFactory,
        ]
    )->makePartial()
        ->shouldAllowMockingProtectedMethods();

    // Mock prepareDatabase
    $restoreTask->shouldAllowMockingProtectedMethods();
    $restoreTask
        ->shouldReceive('prepareDatabase')
        ->once()
        ->andReturnNull();

    // Mock download
    $this->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });

    // Act & Assert
    $exception = null;
    try {
        $restoreTask->run($restore);
    } catch (\App\Exceptions\ShellProcessFailed $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toBe('Access denied for user');

    // Verify the job status is set to failed
    $restore->refresh();
    $job = $restore->job;

    expect($job)->not->toBeNull();
    expect($job->status)->toBe('failed');
    expect($job->error_message)->toBe('Access denied for user');
    expect($job->completed_at)->not->toBeNull();
});

test('run executes sqlite restore workflow successfully', function () {
    // Arrange
    $sqlitePath = $this->tempDir.'/app.sqlite';

    $sourceServer = createRestoreDatabaseServer([
        'name' => 'Source SQLite',
        'database_type' => 'sqlite',
        'sqlite_path' => $sqlitePath,
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_names' => null,
    ]);

    $targetServer = createRestoreDatabaseServer([
        'name' => 'Target SQLite',
        'database_type' => 'sqlite',
        'sqlite_path' => $sqlitePath,
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_names' => null,
    ]);

    // Create snapshot and update path for restore test
    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.db.gz']);
    $snapshot->job->markCompleted();

    // Create restore job
    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'app.sqlite');

    // Mock download - create a compressed file that will be decompressed
    $this->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->with($restore->snapshot, Mockery::any())
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });

    // Act
    $this->restoreTask->run($restore);

    // Build expected file paths
    $workingDir = $this->tempDir.'/restore-'.$restore->id;
    $compressedFile = $workingDir.'/snapshot.gz';
    $decompressedFile = $workingDir.'/snapshot';

    // Expected commands - SQLite restore is just a file copy
    $expectedCommands = [
        "gzip -d '$compressedFile'",
        "cp '$decompressedFile' '$sqlitePath'",
    ];

    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);

    // Verify job completed
    $restore->refresh();
    expect($restore->job->status)->toBe('completed');
});
