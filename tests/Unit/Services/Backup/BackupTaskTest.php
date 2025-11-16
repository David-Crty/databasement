<?php

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Databases\MysqlDatabaseInterface;
use App\Services\Backup\Databases\PostgresqlDatabaseInterface;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\GzipCompressor;
use App\Services\Backup\ShellProcessor;
use League\Flysystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    // Mock dependencies
    $this->mysqlDatabase = Mockery::mock(MysqlDatabaseInterface::class);
    $this->postgresqlDatabase = Mockery::mock(PostgresqlDatabaseInterface::class);
    $this->shellProcessor = Mockery::mock(ShellProcessor::class);
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->compressor = Mockery::mock(GzipCompressor::class);

    // Create the BackupTask instance
    $this->backupTask = new BackupTask(
        $this->mysqlDatabase,
        $this->postgresqlDatabase,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressor
    );

    // Create temp directory for test files
    $this->tempDir = sys_get_temp_dir().'/backup-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);

    // Track created files for cleanup
    $this->createdFiles = [];
});

afterEach(function () {
    // Clean up created files
    foreach ($this->createdFiles as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // Remove temp directory
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }

    Mockery::close();
});
test('run executes mysql backup workflow successfully', function () {
    // Arrange
    $volume = new Volume([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['root' => $this->tempDir],
    ]);
    $volume->id = '01JCTEST0000000000000001';

    $backup = new Backup([
        'recurrence' => 'daily',
    ]);
    $backup->id = '01JCTEST0000000000000002';
    $backup->setRelation('volume', $volume);

    $databaseServer = new DatabaseServer([
        'name' => 'Production MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'myapp',
    ]);
    $databaseServer->id = '01JCTEST0000000000000003';
    $databaseServer->setRelation('backup', $backup);

    $compressedFile = $this->tempDir.'/backup.gz';

    // Mock filesystem
    $filesystem = Mockery::mock(Filesystem::class);

    // Expectations
    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->once()
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->with('local')
        ->once()
        ->andReturn($filesystem);

    // Database configuration
    $this->mysqlDatabase
        ->shouldReceive('setConfig')
        ->once()
        ->with([
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'pass' => 'secret',
            'database' => 'myapp',
        ]);

    // Database dump
    $this->mysqlDatabase
        ->shouldReceive('getDumpCommandLine')
        ->once()
        ->with(Mockery::pattern('#^'.$this->tempDir.'/[a-f0-9]+$#'))
        ->andReturn('mysqldump --routines myapp');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Process::class));

    // Compression - create the compressed file as side effect
    $this->compressor
        ->shouldReceive('getCompressCommandLine')
        ->once()
        ->with(Mockery::pattern('#^'.$this->tempDir.'/[a-f0-9]+$#'))
        ->andReturn('gzip');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Process::class))
        ->andReturnUsing(function () use ($compressedFile) {
            // Simulate the compression creating the file
            file_put_contents($compressedFile, 'compressed backup data');
            $this->createdFiles[] = $compressedFile;

            return '';
        });

    $this->compressor
        ->shouldReceive('getCompressedPath')
        ->once()
        ->with(Mockery::pattern('#^'.$this->tempDir.'/[a-f0-9]+$#'))
        ->andReturn($compressedFile);

    // Transfer
    $filesystem
        ->shouldReceive('writeStream')
        ->once()
        ->with(
            Mockery::pattern('#^Production-MySQL-myapp-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$#'),
            Mockery::type('resource')
        );

    // Act
    $this->backupTask->run($databaseServer);

    // Assert - Mockery will verify all expectations
    expect(true)->toBeTrue();
});

test('run executes postgresql backup workflow successfully', function () {
    // Arrange
    $volume = new Volume([
        'name' => 'Test Volume',
        'type' => 's3',
        'config' => ['root' => $this->tempDir],
    ]);
    $volume->id = '01JCTEST0000000000000004';

    $backup = new Backup([
        'recurrence' => 'hourly',
    ]);
    $backup->id = '01JCTEST0000000000000005';
    $backup->setRelation('volume', $volume);

    $databaseServer = new DatabaseServer([
        'name' => 'Staging PostgreSQL',
        'host' => 'db.example.com',
        'port' => 5432,
        'database_type' => 'postgresql',
        'username' => 'postgres',
        'password' => 'pg_secret',
        'database_name' => 'staging_db',
    ]);
    $databaseServer->id = '01JCTEST0000000000000006';
    $databaseServer->setRelation('backup', $backup);

    $compressedFile = $this->tempDir.'/test.gz';

    // Mock filesystem
    $filesystem = Mockery::mock(Filesystem::class);

    // Expectations
    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->once()
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->with('s3')
        ->once()
        ->andReturn($filesystem);

    // Database configuration
    $this->postgresqlDatabase
        ->shouldReceive('setConfig')
        ->once()
        ->with([
            'host' => 'db.example.com',
            'port' => 5432,
            'user' => 'postgres',
            'pass' => 'pg_secret',
            'database' => 'staging_db',
        ]);

    // Database dump
    $this->postgresqlDatabase
        ->shouldReceive('getDumpCommandLine')
        ->once()
        ->with(Mockery::pattern('#^'.$this->tempDir.'/[a-f0-9]+$#'))
        ->andReturn('pg_dump staging_db');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Process::class));

    // Compression
    $this->compressor
        ->shouldReceive('getCompressCommandLine')
        ->once()
        ->andReturn('gzip');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Process::class))
        ->andReturnUsing(function () use ($compressedFile) {
            file_put_contents($compressedFile, 'compressed backup data');
            $this->createdFiles[] = $compressedFile;

            return '';
        });

    $this->compressor
        ->shouldReceive('getCompressedPath')
        ->once()
        ->andReturn($compressedFile);

    // Transfer
    $filesystem
        ->shouldReceive('writeStream')
        ->once()
        ->with(
            Mockery::pattern('#^Staging-PostgreSQL-staging_db-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$#'),
            Mockery::type('resource')
        );

    // Act
    $this->backupTask->run($databaseServer);

    // Assert
    expect(true)->toBeTrue();
});

test('run executes mariadb backup workflow successfully', function () {
    // Arrange
    $volume = new Volume([
        'name' => 'MariaDB Backups',
        'type' => 'local',
        'config' => ['root' => $this->tempDir],
    ]);
    $volume->id = '01JCTEST0000000000000007';

    $backup = new Backup([
        'recurrence' => 'daily',
    ]);
    $backup->id = '01JCTEST0000000000000008';
    $backup->setRelation('volume', $volume);

    $databaseServer = new DatabaseServer([
        'name' => 'MariaDB Server',
        'host' => 'mariadb.local',
        'port' => 3306,
        'database_type' => 'mariadb',
        'username' => 'admin',
        'password' => 'admin123',
        'database_name' => 'app_data',
    ]);
    $databaseServer->id = '01JCTEST0000000000000009';
    $databaseServer->setRelation('backup', $backup);

    $compressedFile = $this->tempDir.'/dump.sql.gz';

    // Mock filesystem
    $filesystem = Mockery::mock(Filesystem::class);

    // Expectations
    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->once()
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->with('local')
        ->once()
        ->andReturn($filesystem);

    // MariaDB should use MySQL interface
    $this->mysqlDatabase
        ->shouldReceive('setConfig')
        ->once()
        ->with([
            'host' => 'mariadb.local',
            'port' => 3306,
            'user' => 'admin',
            'pass' => 'admin123',
            'database' => 'app_data',
        ]);

    $this->mysqlDatabase
        ->shouldReceive('getDumpCommandLine')
        ->once()
        ->andReturn('mysqldump app_data');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once();

    $this->compressor
        ->shouldReceive('getCompressCommandLine')
        ->once()
        ->andReturn('gzip');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->andReturnUsing(function () use ($compressedFile) {
            file_put_contents($compressedFile, 'compressed backup data');
            $this->createdFiles[] = $compressedFile;

            return '';
        });

    $this->compressor
        ->shouldReceive('getCompressedPath')
        ->once()
        ->andReturn($compressedFile);

    $filesystem
        ->shouldReceive('writeStream')
        ->once();

    // Act
    $this->backupTask->run($databaseServer);

    // Assert
    expect(true)->toBeTrue();
});

test('run throws exception for unsupported database type', function () {
    // Arrange
    $volume = new Volume([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['root' => $this->tempDir],
    ]);
    $volume->id = '01JCTEST000000000000000A';

    $backup = new Backup([
        'recurrence' => 'daily',
    ]);
    $backup->id = '01JCTEST000000000000000B';
    $backup->setRelation('volume', $volume);

    $databaseServer = new DatabaseServer([
        'name' => 'Oracle DB',
        'host' => 'localhost',
        'port' => 1521,
        'database_type' => 'oracle',
        'username' => 'system',
        'password' => 'oracle',
        'database_name' => 'orcl',
    ]);
    $databaseServer->id = '01JCTEST000000000000000C';
    $databaseServer->setRelation('backup', $backup);

    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->with('local')
        ->andReturn(Mockery::mock(Filesystem::class));

    // Act & Assert
    expect(fn () => $this->backupTask->run($databaseServer))
        ->toThrow(\Exception::class, 'Database type oracle not supported');
});

test('run handles database server without database_name gracefully', function () {
    // Arrange
    $volume = new Volume([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['root' => $this->tempDir],
    ]);
    $volume->id = '01JCTEST000000000000000D';

    $backup = new Backup([
        'recurrence' => 'daily',
    ]);
    $backup->id = '01JCTEST000000000000000E';
    $backup->setRelation('volume', $volume);

    $databaseServer = new DatabaseServer([
        'name' => 'Test Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => null, // No database name
    ]);
    $databaseServer->id = '01JCTEST000000000000000F';
    $databaseServer->setRelation('backup', $backup);

    $compressedFile = $this->tempDir.'/backup.gz';
    $filesystem = Mockery::mock(Filesystem::class);

    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->andReturn($filesystem);

    $this->mysqlDatabase
        ->shouldReceive('setConfig')
        ->once()
        ->with([
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'pass' => 'secret',
            'database' => null,
        ]);

    $this->mysqlDatabase
        ->shouldReceive('getDumpCommandLine')
        ->once()
        ->andReturn('mysqldump');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once();

    $this->compressor
        ->shouldReceive('getCompressCommandLine')
        ->once()
        ->andReturn('gzip');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->andReturnUsing(function () use ($compressedFile) {
            file_put_contents($compressedFile, 'compressed backup data');
            $this->createdFiles[] = $compressedFile;

            return '';
        });

    $this->compressor
        ->shouldReceive('getCompressedPath')
        ->once()
        ->andReturn($compressedFile);

    // Should use 'db' as default in filename
    $filesystem
        ->shouldReceive('writeStream')
        ->once()
        ->with(
            Mockery::pattern('#^Test-Server-db-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$#'),
            Mockery::type('resource')
        );

    // Act
    $this->backupTask->run($databaseServer);

    // Assert
    expect(true)->toBeTrue();
});

test('run sanitizes special characters in filenames', function () {
    // Arrange
    $volume = new Volume([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['root' => $this->tempDir],
    ]);
    $volume->id = '01JCTEST0000000000000010';

    $backup = new Backup([
        'recurrence' => 'daily',
    ]);
    $backup->id = '01JCTEST0000000000000011';
    $backup->setRelation('volume', $volume);

    $databaseServer = new DatabaseServer([
        'name' => 'My@Server#With$Special%Chars!',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_name' => 'database/with\\slashes',
    ]);
    $databaseServer->id = '01JCTEST0000000000000012';
    $databaseServer->setRelation('backup', $backup);

    $compressedFile = $this->tempDir.'/backup.gz';
    $filesystem = Mockery::mock(Filesystem::class);

    $this->filesystemProvider
        ->shouldReceive('getConfig')
        ->andReturn($this->tempDir);

    $this->filesystemProvider
        ->shouldReceive('get')
        ->andReturn($filesystem);

    $this->mysqlDatabase
        ->shouldReceive('setConfig')
        ->once();

    $this->mysqlDatabase
        ->shouldReceive('getDumpCommandLine')
        ->once()
        ->andReturn('mysqldump');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once();

    $this->compressor
        ->shouldReceive('getCompressCommandLine')
        ->once()
        ->andReturn('gzip');

    $this->shellProcessor
        ->shouldReceive('process')
        ->once()
        ->andReturnUsing(function () use ($compressedFile) {
            file_put_contents($compressedFile, 'compressed backup data');
            $this->createdFiles[] = $compressedFile;

            return '';
        });

    $this->compressor
        ->shouldReceive('getCompressedPath')
        ->once()
        ->andReturn($compressedFile);

    // Filename should have special chars replaced with dashes
    $filesystem
        ->shouldReceive('writeStream')
        ->once()
        ->with(
            Mockery::pattern('#^My-Server-With-Special-Chars--database-with-slashes-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$#'),
            Mockery::type('resource')
        );

    // Act
    $this->backupTask->run($databaseServer);

    // Assert
    expect(true)->toBeTrue();
});
