<?php

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\Backup\BackupResult;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
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
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->andReturn($mockHandler);

    return $mockFactory;
}

function buildServer(string $name = 'Test Server'): DatabaseServer
{
    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);
    $server->name = $name;

    return $server;
}

function buildVolume(): Volume
{
    $volume = new Volume;
    $volume->type = 'local';
    $volume->config = ['root' => '/tmp/backups'];
    $volume->name = 'Test Volume';

    return $volume;
}

test('execute returns BackupResult with filename, fileSize, and checksum', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transfer')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/execute-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $result = $backupTask->execute(
        server: buildServer(),
        databaseName: 'myapp',
        volume: buildVolume(),
        logger: new InMemoryBackupLogger,
        workingDirectory: $workingDirectory,
    );

    expect($result)->toBeInstanceOf(BackupResult::class)
        ->and($result->filename)->toContain('Test-Server-myapp-')
        ->and($result->filename)->toEndWith('.sql.gz')
        ->and($result->fileSize)->toBeGreaterThan(0)
        ->and($result->checksum)->toMatch('/^[a-f0-9]{64}$/');
});

test('execute calls onProgress callback at each checkpoint', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transfer')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/progress-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $progressCount = 0;

    $backupTask->execute(
        server: buildServer(),
        databaseName: 'myapp',
        volume: buildVolume(),
        logger: new InMemoryBackupLogger,
        workingDirectory: $workingDirectory,
        onProgress: function () use (&$progressCount) {
            $progressCount++;
        },
    );

    expect($progressCount)->toBe(3);
});

test('execute establishes SSH tunnel when server requires it', function () {
    $sshConfig = \App\Models\DatabaseServerSshConfig::factory()->create();

    $server = createDatabaseServer([
        'name' => 'MySQL via SSH',
        'host' => 'private-db.internal',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
        'ssh_config_id' => $sshConfig->id,
    ]);

    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(
            command: "echo 'fake dump' > ".escapeshellarg($outputPath),
        ));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeForServer')
        ->once()
        ->with(
            Mockery::on(fn ($s) => $s->id === $server->id),
            'myapp',
            '127.0.0.1',
            54321
        )
        ->andReturn($mockHandler);

    $this->filesystemProvider->shouldReceive('transfer')->once();

    $sshTunnelService = Mockery::mock(SshTunnelService::class);
    $sshTunnelService->shouldReceive('establish')
        ->once()
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
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

    $result = $backupTask->execute(
        server: $server,
        databaseName: 'myapp',
        volume: buildVolume(),
        logger: new InMemoryBackupLogger,
        workingDirectory: $workingDirectory,
    );

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
    $mockProvider->shouldReceive('makeForServer')
        ->once()
        ->with(
            Mockery::type(DatabaseServer::class),
            'myapp',
            'localhost',
            3306
        )
        ->andReturn($mockHandler);

    $this->filesystemProvider->shouldReceive('transfer')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/fallback-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $backupTask->execute(
        server: buildServer(),
        databaseName: 'myapp',
        volume: buildVolume(),
        logger: new InMemoryBackupLogger,
        workingDirectory: $workingDirectory,
    );
});

test('execute cleans up working directory on success', function () {
    $mockProvider = buildMockDatabaseProvider();

    $this->filesystemProvider->shouldReceive('transfer')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/cleanup-success-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $backupTask->execute(
        server: buildServer(),
        databaseName: 'myapp',
        volume: buildVolume(),
        logger: new InMemoryBackupLogger,
        workingDirectory: $workingDirectory,
    );

    expect(is_dir($workingDirectory))->toBeFalse();
});

test('execute cleans up working directory on failure', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: 'false'));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeForServer')
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

    $workingDirectory = $this->tempDir.'/cleanup-failure-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    try {
        $backupTask->execute(
            server: buildServer(),
            databaseName: 'myapp',
            volume: buildVolume(),
            logger: new InMemoryBackupLogger,
            workingDirectory: $workingDirectory,
        );
    } catch (\App\Exceptions\ShellProcessFailed) {
        // Expected
    }

    expect(is_dir($workingDirectory))->toBeFalse();
});

test('execute uses custom compression type and level', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(
            command: "echo 'fake dump' > ".escapeshellarg($outputPath),
        ));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeForServer')
        ->once()
        ->andReturn($mockHandler);

    $this->filesystemProvider->shouldReceive('transfer')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $workingDirectory = $this->tempDir.'/compression-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $result = $backupTask->execute(
        server: buildServer(),
        databaseName: 'myapp',
        volume: buildVolume(),
        logger: new InMemoryBackupLogger,
        workingDirectory: $workingDirectory,
        compressionType: CompressionType::ZSTD,
        compressionLevel: 5,
    );

    expect($result->filename)->toEndWith('.sql.zst');

    // Verify zstd command was used with level 5
    $zstdCommands = array_filter(
        $this->shellProcessor->getCommands(),
        fn (string $cmd) => str_starts_with($cmd, 'zstd'),
    );
    expect($zstdCommands)->not->toBeEmpty();
    expect(array_values($zstdCommands)[0])->toContain('-5');
});
