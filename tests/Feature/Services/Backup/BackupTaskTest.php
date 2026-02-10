<?php

use App\Facades\AppConfig;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\CompressorFactory;
use App\Services\Backup\DatabaseListService;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\Filesystem;
use Tests\Support\TestShellProcessor;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use REAL services for command building
    $this->mysqlDatabase = new MysqlDatabase;  // ✓ Real command building
    $this->postgresqlDatabase = new PostgresqlDatabase;  // ✓ Real command building
    $this->shellProcessor = new TestShellProcessor;  // ✓ Captures commands without executing
    $this->compressorFactory = new CompressorFactory($this->shellProcessor);  // ✓ Real path manipulation

    // Mock external dependencies only
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    // SSH tunnel is not active for these tests (no SSH configured)
    $this->sshTunnelService->shouldReceive('isActive')->andReturn(false);

    // Create the BackupTask instance
    $this->backupTask = new BackupTask(
        $this->mysqlDatabase,
        $this->postgresqlDatabase,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService
    );

    // Use real BackupJobFactory from container
    $this->backupJobFactory = app(BackupJobFactory::class);

    // Create temp directory for test files and set config
    $this->tempDir = sys_get_temp_dir().'/backup-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    AppConfig::set('backup.working_directory', $this->tempDir);
    AppConfig::set('backup.compression', 'gzip');
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
        'backup_schedule_id' => dailySchedule()->id,
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
        $this->compressorFactory,
        $this->sshTunnelService
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

test('run establishes SSH tunnel when server requires it', function () {
    // Arrange - Create a server with SSH tunnel configured
    $sshConfig = DatabaseServerSshConfig::create([
        'host' => 'bastion.example.com',
        'port' => 22,
        'username' => 'tunnel_user',
        'auth_type' => 'password',
        'password' => 'ssh_secret',
    ]);

    $databaseServer = createDatabaseServer([
        'name' => 'MySQL via SSH',
        'host' => 'private-db.internal',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
        'ssh_config_id' => $sshConfig->id,
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($databaseServer, 'manual');
    $snapshot = $snapshots[0];

    // Configure SSH tunnel mock to expect establishment and closure
    $sshTunnelService = Mockery::mock(SshTunnelService::class);
    $sshTunnelService->shouldReceive('establish')
        ->once()
        ->with(Mockery::on(fn ($server) => $server->id === $databaseServer->id))
        ->andReturn(['host' => '127.0.0.1', 'port' => 54321]);
    $sshTunnelService->shouldReceive('isActive')
        ->andReturn(true);
    $sshTunnelService->shouldReceive('close')
        ->once();

    // Create BackupTask with configured SSH mock
    $backupTask = new BackupTask(
        $this->mysqlDatabase,
        $this->postgresqlDatabase,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $sshTunnelService
    );

    setupCommonExpectations($snapshot);
    $backupTask->run($snapshot);

    // Build expected file paths
    $workingDir = $this->tempDir.'/backup-'.$snapshot->id;
    $sqlFile = $workingDir.'/dump.sql';

    // Verify that the dump command uses the tunnel endpoint (127.0.0.1:54321)
    // instead of the original host (private-db.internal:3306)
    $commands = $this->shellProcessor->getCommands();
    expect($commands[0])->toContain("--host='127.0.0.1'")
        ->and($commands[0])->toContain("--port='54321'")
        ->and($commands[0])->not->toContain('private-db.internal');

    // Verify backup completed
    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('completed');
});
