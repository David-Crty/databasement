<?php

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\DTO\RestoreConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\InMemoryBackupLogger;
use App\Services\Backup\RestoreTask;
use App\Services\SshTunnelService;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    $this->compressorFactory = new CompressorFactory($this->shellProcessor);

    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    $this->sshTunnelService->shouldReceive('isActive')->andReturn(false);

    $this->tempDir = sys_get_temp_dir().'/restore-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    AppConfig::set('backup.working_directory', $this->tempDir);
});

function buildTargetConfig(string $host = 'localhost'): DatabaseConnectionConfig
{
    return new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'Target Server',
        host: $host,
        port: 3306,
        username: 'root',
        password: 'secret',
    );
}

function buildSnapshotVolumeConfig(): VolumeConfig
{
    return new VolumeConfig(
        type: 'local',
        name: 'Test Volume',
        config: ['root' => '/tmp/backups'],
    );
}

function buildRestoreConfig(?string $workingDirectory = null): RestoreConfig
{
    return new RestoreConfig(
        targetServer: buildTargetConfig(),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.sql.gz',
        snapshotFileSize: 1024,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::MYSQL,
        snapshotDatabaseName: 'sourcedb',
        schemaName: 'restored_db',
        workingDirectory: $workingDirectory ?? test()->tempDir.'/restore-test-'.uniqid(),
    );
}

function buildMockRestoreProvider(): DatabaseProvider
{
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('restore')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "echo 'fake restore'"));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->andReturn($mockHandler);

    return $mockProvider;
}

function setupDownloadMock(): void
{
    test()->filesystemProvider
        ->shouldReceive('downloadFromConfig')
        ->once()
        ->andReturnUsing(function ($volumeConfig, $remoteFilename, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });
}

test('execute restores successfully', function () {
    $mockProvider = buildMockRestoreProvider();
    setupDownloadMock();

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildRestoreConfig();
    mkdir($config->workingDirectory, 0755, true);

    $logger = new InMemoryBackupLogger;
    $restoreTask->execute($config, $logger);

    $successLogs = collect($logger->getLogs())
        ->where('level', 'success')
        ->pluck('message')
        ->toArray();

    expect($successLogs)->toContain('Restore completed successfully');
});

test('execute throws when database types are incompatible', function () {
    $restoreTask = new RestoreTask(
        new DatabaseProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = new RestoreConfig(
        targetServer: new DatabaseConnectionConfig(
            databaseType: DatabaseType::POSTGRESQL,
            serverName: 'Target PostgreSQL',
            host: 'localhost',
            port: 5432,
            username: 'postgres',
            password: 'secret',
        ),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.sql.gz',
        snapshotFileSize: 1024,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::MYSQL,
        snapshotDatabaseName: 'sourcedb',
        schemaName: 'restored_db',
        workingDirectory: $this->tempDir.'/compat-test-'.uniqid(),
    );

    mkdir($config->workingDirectory, 0755, true);

    expect(fn () => $restoreTask->execute($config, new InMemoryBackupLogger))
        ->toThrow(\App\Exceptions\Backup\RestoreException::class, 'Cannot restore mysql snapshot to postgres server');
});

test('execute throws for Redis restore', function () {
    $restoreTask = new RestoreTask(
        new DatabaseProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = new RestoreConfig(
        targetServer: new DatabaseConnectionConfig(
            databaseType: DatabaseType::REDIS,
            serverName: 'Redis Server',
            host: 'localhost',
            port: 6379,
            username: '',
            password: '',
        ),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.rdb.gz',
        snapshotFileSize: 512,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::REDIS,
        snapshotDatabaseName: 'all',
        schemaName: 'all',
        workingDirectory: $this->tempDir.'/redis-test-'.uniqid(),
    );

    mkdir($config->workingDirectory, 0755, true);

    expect(fn () => $restoreTask->execute($config, new InMemoryBackupLogger))
        ->toThrow(\App\Exceptions\Backup\RestoreException::class, 'Automated restore is not supported for Redis/Valkey');
});

test('execute establishes SSH tunnel when target server requires it', function () {
    $targetConfig = new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'MySQL via SSH',
        host: 'private-db.internal',
        port: 3306,
        username: 'root',
        password: 'secret',
        sshConfig: [
            'host' => 'ssh.example.com',
            'port' => 22,
            'username' => 'deploy',
            'auth_type' => 'password',
            'password' => 'sshpass',
            'private_key' => null,
            'key_passphrase' => null,
        ],
    );

    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('restore')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "echo 'fake restore'"));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->host === 'private-db.internal'),
            'restored_db',
            '127.0.0.1',
            54321,
            'sourcedb',
        )
        ->andReturn($mockHandler);

    setupDownloadMock();

    $sshTunnelService = Mockery::mock(SshTunnelService::class);
    $sshTunnelService->shouldReceive('establishFromConfig')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c['host'] === 'ssh.example.com'),
            'private-db.internal',
            3306
        )
        ->andReturn(['host' => '127.0.0.1', 'port' => 54321]);
    $sshTunnelService->shouldReceive('isActive')->andReturn(true);
    $sshTunnelService->shouldReceive('close')->once();

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/ssh-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $config = new RestoreConfig(
        targetServer: $targetConfig,
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.sql.gz',
        snapshotFileSize: 1024,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::MYSQL,
        snapshotDatabaseName: 'sourcedb',
        schemaName: 'restored_db',
        workingDirectory: $workingDirectory,
    );

    $restoreTask->execute($config, new InMemoryBackupLogger);
});

test('execute cleans up working directory on success', function () {
    $mockProvider = buildMockRestoreProvider();
    setupDownloadMock();

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildRestoreConfig();
    mkdir($config->workingDirectory, 0755, true);

    $restoreTask->execute($config, new InMemoryBackupLogger);

    expect(is_dir($config->workingDirectory))->toBeFalse();
});

test('execute cleans up working directory on failure', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('restore')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "mysql 'restored_db'"));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->andReturn($mockHandler);

    setupDownloadMock();

    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Command failed'));

    $compressor = Mockery::mock(\App\Services\Backup\Compressors\CompressorInterface::class);
    $compressor->shouldReceive('getExtension')->andReturn('gz');
    $compressor->shouldReceive('decompress')
        ->once()
        ->andReturnUsing(function ($compressedFile) {
            $decompressedFile = preg_replace('/\.gz$/', '', $compressedFile);
            file_put_contents($decompressedFile, "-- Fake decompressed data\n");

            return $decompressedFile;
        });

    $compressorFactory = Mockery::mock(\App\Services\Backup\Compressors\CompressorFactory::class);
    $compressorFactory->shouldReceive('make')->andReturn($compressor);

    $restoreTask = new RestoreTask(
        $mockProvider,
        $shellProcessor,
        $this->filesystemProvider,
        $compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildRestoreConfig();
    mkdir($config->workingDirectory, 0755, true);

    expect(fn () => $restoreTask->execute($config, new InMemoryBackupLogger))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class);

    expect(is_dir($config->workingDirectory))->toBeFalse();
});
