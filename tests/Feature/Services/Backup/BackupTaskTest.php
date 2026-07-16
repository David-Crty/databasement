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
use App\Services\Backup\PostScriptRunner;
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
        new PostScriptRunner,
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
        new PostScriptRunner,
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
        new PostScriptRunner,
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
        new PostScriptRunner,
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
        new PostScriptRunner,
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
        new PostScriptRunner,
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
        new PostScriptRunner,
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

test('execute runs post-backup script with backup variables after successful backup', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
        new PostScriptRunner,
    );

    $workingDirectory = $this->tempDir.'/post-script-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $config = new BackupConfig(
        database: buildDbConfig(),
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
        postBackupScript: 'echo "$BACKUP_FILENAME"',
    );

    $result = $backupTask->execute($config, new InMemoryBackupLogger);

    $commands = $this->shellProcessor->getCommands();
    $lastEnv = end($this->shellProcessor->executedEnv);

    expect($result)->toBeInstanceOf(BackupResult::class)
        ->and($commands)->not->toBeEmpty()
        ->and(end($commands))->toContain('post-backup-script.sh')
        ->and(count($commands))->toBeGreaterThan(1)
        ->and($lastEnv)->toHaveKey('BACKUP_DATABASE_NAME', 'myapp')
        ->and($lastEnv)->toHaveKey('BACKUP_FILENAME')
        ->and($lastEnv)->toHaveKey('BACKUP_CHECKSUM');
});

test('execute skips post-backup script when none configured', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = new BackupTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
        new PostScriptRunner,
    );

    $workingDirectory = $this->tempDir.'/post-script-none-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $config = new BackupConfig(
        database: buildDbConfig(),
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
        postBackupScript: '   ',
    );

    $backupTask->execute($config, new InMemoryBackupLogger);

    $commands = $this->shellProcessor->getCommands();
    expect(collect($commands)->contains(fn ($cmd) => str_contains($cmd, 'post-backup-script.sh')))->toBeFalse();
});

test('execute logs warning and continues when post-backup script fails', function () {
    $mockProvider = buildMockDatabaseProvider();

    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $throwingShellProcessor = new class extends TestShellProcessor
    {
        public function process(string $command, array $env = []): string
        {
            if (str_contains($command, 'post-backup-script.sh')) {
                throw new \App\Exceptions\ShellProcessFailed('Script exited with code 1');
            }

            return parent::process($command, $env);
        }
    };

    $compressorFactory = new CompressorFactory($throwingShellProcessor);

    $backupTask = new BackupTask(
        $mockProvider,
        $throwingShellProcessor,
        $this->filesystemProvider,
        $compressorFactory,
        $this->sshTunnelService,
        new PostScriptRunner,
    );

    $workingDirectory = $this->tempDir.'/post-script-fail-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    $logger = new InMemoryBackupLogger;

    $config = new BackupConfig(
        database: buildDbConfig(),
        volume: buildVolumeConfig(),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
        postBackupScript: 'exit 1',
    );

    $result = $backupTask->execute($config, $logger);

    expect($result)->toBeInstanceOf(BackupResult::class);

    $warningLogs = array_filter($logger->getLogs(), fn ($log) => ($log['level'] ?? '') === 'warning');
    expect($warningLogs)->not->toBeEmpty();
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
        new PostScriptRunner,
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

function buildQuotaBackupTask(): BackupTask
{
    return new BackupTask(
        buildMockDatabaseProvider(),
        test()->shellProcessor,
        test()->filesystemProvider,
        test()->compressorFactory,
        test()->sshTunnelService,
        new PostScriptRunner,
    );
}

/**
 * @param  array<string, mixed>  $volumeConfig
 */
function buildQuotaConfig(array $volumeConfig, ?int $volumeUsedBytes): BackupConfig
{
    $workingDirectory = test()->tempDir.'/quota-test-'.uniqid();
    mkdir($workingDirectory, 0755, true);

    return new BackupConfig(
        database: buildDbConfig(),
        volume: new VolumeConfig(type: 'local', name: 'R2 Bucket', config: $volumeConfig),
        databaseName: 'myapp',
        workingDirectory: $workingDirectory,
        volumeUsedBytes: $volumeUsedBytes,
    );
}

test('execute aborts before upload when the backup would exceed the volume storage limit', function () {
    // The upload must never be attempted once the quota is blown.
    test()->filesystemProvider->shouldReceive('transferFromConfig')->never();

    $backupTask = buildQuotaBackupTask();
    $config = buildQuotaConfig(['max_storage_bytes' => 10], volumeUsedBytes: 9);

    expect(fn () => $backupTask->execute($config, new InMemoryBackupLogger))
        ->toThrow(\App\Exceptions\Backup\StorageQuotaExceededException::class);
});

test('execute uploads when the backup fits within the limit or usage is unknown', function (?int $volumeUsedBytes, int $limit) {
    // Fits under the limit, or (remote agent) usage is unknown — volumeUsedBytes
    // is null so the limit cannot be enforced. Either way the upload proceeds.
    test()->filesystemProvider->shouldReceive('transferFromConfig')->once();

    $backupTask = buildQuotaBackupTask();
    $config = buildQuotaConfig(['max_storage_bytes' => $limit], volumeUsedBytes: $volumeUsedBytes);

    expect($backupTask->execute($config, new InMemoryBackupLogger))->toBeInstanceOf(BackupResult::class);
})->with([
    'fits within limit' => [0, 1024 ** 3],
    'usage unknown (agent)' => [null, 10],
]);
