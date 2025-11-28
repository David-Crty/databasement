<?php

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\DatabaseSizeCalculator;
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
    $this->compressor = new GzipCompressor;  // ✓ Real path manipulation
    $this->shellProcessor = new TestShellProcessor;  // ✓ Captures commands without executing

    // Mock external dependencies only
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->databaseSizeCalculator = Mockery::mock(DatabaseSizeCalculator::class);

    // Create the BackupTask instance
    $this->backupTask = new BackupTask(
        $this->mysqlDatabase,
        $this->postgresqlDatabase,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressor,
        $this->databaseSizeCalculator
    );

    // Create temp directory for test files
    $this->tempDir = sys_get_temp_dir().'/backup-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
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
function setupCommonExpectations(DatabaseServer $databaseServer, ?int $databaseSize = 1024000): void
{
    $filesystem = Mockery::mock(Filesystem::class);

    // Database size calculator
    test()->databaseSizeCalculator
        ->shouldReceive('calculate')
        ->once()
        ->with($databaseServer)
        ->andReturn($databaseSize);

    // Filesystem provider
    test()->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->andReturn(test()->tempDir);

    test()->filesystemProvider
        ->shouldReceive('get')
        ->with($databaseServer->backup->volume->type)
        ->andReturn($filesystem);

    test()->filesystemProvider
        ->shouldReceive('transfert')
        ->once();
}

afterEach(function () {
    // Remove temp directory
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }

    Mockery::close();
});

test('run executes mysql backup workflow successfully', function () {
    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'Production MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'myapp',
    ]);

    setupCommonExpectations($databaseServer, 1024000);
    $snapshot = $this->backupTask->run($databaseServer, $this->tempDir);
    $sqlFile = $this->tempDir.'/'.$snapshot->id.'.sql';

    $expectedCommands = [
        "mariadb-dump --routines --skip_ssl --host='localhost' --port='3306' --user='root' --password='secret' 'myapp' > '$sqlFile'",
        "gzip '$sqlFile'",
    ];
    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
});

test('run executes postgresql backup workflow successfully', function () {
    // Arrange
    $databaseServer = createDatabaseServer([
        'name' => 'Staging PostgreSQL',
        'host' => 'db.example.com',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'postgres',
        'password' => 'pg_secret',
        'database_name' => 'staging_db',
    ], 's3');

    setupCommonExpectations($databaseServer, 2048000);
    $snapshot = $this->backupTask->run($databaseServer, $this->tempDir);
    $sqlFile = $this->tempDir.'/'.$snapshot->id.'.sql';

    $expectedCommands = [
        "PGPASSWORD='pg_secret' pg_dump --clean --host='db.example.com' --port='5432' --username='postgres' 'staging_db' -f '$sqlFile'",
        "gzip '$sqlFile'",
    ];
    $commands = $this->shellProcessor->getCommands();
    expect($commands)->toEqual($expectedCommands);
});

test('run executes mariadb backup workflow successfully', function () {
    // Arrange - MariaDB uses MySQL interface
    $databaseServer = createDatabaseServer([
        'name' => 'MariaDB Server',
        'host' => 'mariadb.local',
        'port' => 3306,
        'database_type' => 'mariadb',
        'username' => 'admin',
        'password' => 'admin123',
        'database_name' => 'app_data',
    ]);

    setupCommonExpectations($databaseServer, 512000);
    $snapshot = $this->backupTask->run($databaseServer, $this->tempDir);
    $sqlFile = $this->tempDir.'/'.$snapshot->id.'.sql';

    $expectedCommands = [
        "mariadb-dump --routines --skip_ssl --host='mariadb.local' --port='3306' --user='admin' --password='admin123' 'app_data' > '$sqlFile'",
        "gzip '$sqlFile'",
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
        'database_name' => 'orcl',
    ]);

    // Only set up expectations for operations that happen before the exception
    $this->databaseSizeCalculator
        ->shouldReceive('calculate')
        ->once()
        ->with($databaseServer)
        ->andReturn(null);

    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->with('local')
        ->andReturn(Mockery::mock(\League\Flysystem\Filesystem::class));

    // Act & Assert
    expect(fn () => $this->backupTask->run($databaseServer))
        ->toThrow(\Exception::class, 'Database type oracle not supported');
});
