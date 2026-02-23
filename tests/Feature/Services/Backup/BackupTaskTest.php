<?php

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\InMemoryBackupLogger;
use App\Services\SshTunnelService;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    $this->compressorFactory = new CompressorFactory($this->shellProcessor);

    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    $this->sshTunnelService->shouldReceive('isActive')->andReturn(false);

    $this->tempDir = sys_get_temp_dir().'/backup-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    AppConfig::set('backup.working_directory', $this->tempDir);
    AppConfig::set('backup.compression', 'gzip');
});

function buildMockDatabaseProvider(): DatabaseProvider
{
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(
            command: "echo 'fake dump' > ".escapeshellarg($outputPath),
        ));

    $mockFactory = Mockery::mock(DatabaseProvider::class);
    $mockFactory->shouldReceive('makeFromConfig')
        ->once()
        ->andReturn($mockHandler);

    return $mockFactory;
}

function buildDbConfig(string $name = 'Test Server'): DatabaseConnectionConfig
{
    return new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: $name,
        host: 'localhost',
        port: 3306,
        username: 'root',
        password: 'secret',
    );
}

function buildVolumeConfig(): VolumeConfig
{
    return new VolumeConfig(
        type: 'local',
        name: 'Test Volume',
        config: ['root' => '/tmp/backups'],
    );
}

function buildBackupConfig(?string $workingDirectory = null): BackupConfig
{
    return new BackupConfig(
        database: buildDbConfig(),
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory ?? test()->tempDir.'/execute-test-'.uniqid(),
    );
}

test('execute returns BackupResult with filename, fileSize, and checksum', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildBackupConfig();
    mkdir($config->workingDirectory, 0755, true);

    $result = $backupTask->execute($config, new InMemoryBackupLogger);

    expect($result)->toBeInstanceOf(BackupResult::class)
        ->and($result->filename)->toContain('Test-Server-myapp-')
        ->and($result->filename)->toEndWith('.sql.gz')
        ->and($result->fileSize)->toBeGreaterThan(0)
        ->and($result->checksum)->toMatch('/^[a-f0-9]{64}$/');
});

test('execute calls onProgress callback at each checkpoint', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildBackupConfig();
    mkdir($config->workingDirectory, 0755, true);

    $progressCount = 0;

    $backupTask->execute(
        $config,
        new InMemoryBackupLogger,
        onProgress: function () use (&$progressCount) {
            $progressCount++;
        },
    );

    expect($progressCount)->toBe(3);
});

test('execute establishes SSH tunnel when server requires it', function () {
    $dbConfig = new DatabaseConnectionConfig(
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
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(
            command: "echo 'fake dump' > ".escapeshellarg($outputPath),
        ));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->host === 'private-db.internal'),
            'myapp',
            '127.0.0.1',
            54321
        )
        ->andReturn($mockHandler);

    $this->filesystemProvider->shouldReceive('transferFromConfig')->once();

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

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/ssh-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $config = new BackupConfig(
        database: $dbConfig,
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
    );

    $result = $backupTask->execute($config, new InMemoryBackupLogger);

    expect($result)->toBeInstanceOf(BackupResult::class);
});

test('execute uses server host and port when no SSH tunnel is needed', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(
            command: "echo 'fake dump' > ".escapeshellarg($outputPath),
        ));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->with(
            Mockery::type(DatabaseConnectionConfig::class),
            'myapp',
            'localhost',
            3306
        )
        ->andReturn($mockHandler);

    $this->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildBackupConfig();
    mkdir($config->workingDirectory, 0755, true);

    $backupTask->execute($config, new InMemoryBackupLogger);
});

test('execute cleans up working directory on success', function () {
    $mockProvider = buildMockDatabaseProvider();

    $this->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildBackupConfig();
    mkdir($config->workingDirectory, 0755, true);

    $backupTask->execute($config, new InMemoryBackupLogger);

    expect(is_dir($config->workingDirectory))->toBeFalse();
});

test('execute cleans up working directory on failure', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: 'false'));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->andReturn($mockHandler);

    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Command failed'));

    $backupTask = new BackupTask(
        $mockProvider,
        $shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = buildBackupConfig();
    mkdir($config->workingDirectory, 0755, true);

    expect(fn () => $backupTask->execute($config, new InMemoryBackupLogger))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class);

    expect(is_dir($config->workingDirectory))->toBeFalse();
});

test('execute uses custom compression type and level', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(
            command: "echo 'fake dump' > ".escapeshellarg($outputPath),
        ));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->andReturn($mockHandler);

    $this->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/compression-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $config = new BackupConfig(
        database: buildDbConfig(),
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
        compressionType: CompressionType::ZSTD,
        compressionLevel: 5,
    );

    $result = $backupTask->execute($config, new InMemoryBackupLogger);

    expect($result->filename)->toEndWith('.sql.zst');

    // Verify zstd command was used with level 5
    $zstdCommands = array_filter(
        $this->shellProcessor->getCommands(),
        fn (string $cmd) => str_starts_with($cmd, 'zstd'),
    );
    expect($zstdCommands)->not->toBeEmpty();
    expect(array_values($zstdCommands)[0])->toContain('-5');
});

test('execute prepends backup path with date variables to filename', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/path-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $config = new BackupConfig(
        database: buildDbConfig(),
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
        backupPath: 'backups/{year}/{month}',
    );

    $result = $backupTask->execute($config, new InMemoryBackupLogger);

    $expectedPrefix = 'backups/'.now()->format('Y').'/'.now()->format('m').'/';
    expect($result->filename)->toStartWith($expectedPrefix)
        ->and($result->filename)->toContain('Test-Server-myapp-');
});
