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

const FIREBIRD_SOURCE_DATABASE = '/data/source.fdb';
const FIREBIRD_RESTORE_TARGET_DATABASE = '/data/restore-target.fdb';
const FIREBIRD_RESTORE_COMMAND = "echo 'firebird restore command'";
const COMPRESSED_BACKUP_DATA = 'compressed backup data';

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

function buildFirebirdTargetConfig(string $host = 'fb.local', int $port = 3050): DatabaseConnectionConfig
{
    return new DatabaseConnectionConfig(
        databaseType: DatabaseType::FIREBIRD,
        serverName: 'Target Firebird',
        host: $host,
        port: $port,
        username: 'sysdba',
        password: 'masterkey',
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

function buildFirebirdRestoreConfig(?string $workingDirectory = null): RestoreConfig
{
    return new RestoreConfig(
        targetServer: buildFirebirdTargetConfig(),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.fbk.gz',
        snapshotFileSize: 2048,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::FIREBIRD,
        snapshotDatabaseName: FIREBIRD_SOURCE_DATABASE,
        schemaName: FIREBIRD_RESTORE_TARGET_DATABASE,
        workingDirectory: $workingDirectory ?? test()->tempDir.'/restore-firebird-'.uniqid(),
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

function buildMockFirebirdRestoreProvider(): DatabaseProvider
{
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->with(FIREBIRD_RESTORE_TARGET_DATABASE, Mockery::any(), false)->andReturnNull();
    $mockHandler->shouldReceive('restore')
        ->once()
        ->with(Mockery::on(function (string $workingFile): bool {
            return file_exists($workingFile) && ! str_ends_with($workingFile, '.gz');
        }))
        ->andReturn(new DatabaseOperationResult(command: FIREBIRD_RESTORE_COMMAND));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')
        ->once()
        ->with(
            Mockery::on(fn (DatabaseConnectionConfig $connectionConfig) => $connectionConfig->databaseType === DatabaseType::FIREBIRD),
            FIREBIRD_RESTORE_TARGET_DATABASE,
            'fb.local',
            3050,
            FIREBIRD_SOURCE_DATABASE,
            null,
        )
        ->andReturn($mockHandler);

    return $mockProvider;
}

function setupDownloadMock(): void
{
    test()->filesystemProvider
        ->shouldReceive('downloadFromConfig')
        ->once()
        ->andReturnUsing(function (...$arguments) {
            file_put_contents($arguments[2], COMPRESSED_BACKUP_DATA);
        });
}

function setupFirebirdDownloadMock(RestoreConfig $config): void
{
    test()->filesystemProvider
        ->shouldReceive('downloadFromConfig')
        ->once()
        ->with(
            Mockery::type(VolumeConfig::class),
            'backup.fbk.gz',
            Mockery::on(fn (string $destination) => str_starts_with($destination, $config->workingDirectory.'/')
                && str_ends_with($destination, '.gz'))
        )
        ->andReturnUsing(function (...$arguments) {
            file_put_contents($arguments[2], COMPRESSED_BACKUP_DATA);
        });
}

test('execute calls transferOwnership when ownerUser is set and database is PostgreSQL', function () {
    $mockHandler = Mockery::mock(\App\Services\Backup\Databases\PostgresqlDatabase::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('restore')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "echo 'fake restore'"));
    $mockHandler->shouldReceive('transferOwnership')
        ->once()
        ->with('restored_db', 'app_user', Mockery::type(InMemoryBackupLogger::class));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')->once()->andReturn($mockHandler);

    setupDownloadMock();

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = new RestoreConfig(
        targetServer: buildTargetConfig(),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.sql.gz',
        snapshotFileSize: 1024,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::MYSQL,
        snapshotDatabaseName: 'sourcedb',
        schemaName: 'restored_db',
        workingDirectory: $this->tempDir.'/owner-test-'.uniqid(),
        ownerUser: 'app_user',
    );

    mkdir($config->workingDirectory, 0755, true);

    $logger = new InMemoryBackupLogger;
    $restoreTask->execute($config, $logger);

    $infoLogs = collect($logger->getLogs())->where('level', 'info')->pluck('message')->toArray();
    expect($infoLogs)->toContain('Transferring ownership of database "restored_db" to user "app_user"');
});

test('execute does not call transferOwnership for non-PostgreSQL databases', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('restore')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "echo 'fake restore'"));
    $mockHandler->shouldNotReceive('transferOwnership');

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')->once()->andReturn($mockHandler);

    setupDownloadMock();

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = new RestoreConfig(
        targetServer: buildTargetConfig(),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.sql.gz',
        snapshotFileSize: 1024,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::MYSQL,
        snapshotDatabaseName: 'sourcedb',
        schemaName: 'restored_db',
        workingDirectory: $this->tempDir.'/no-owner-test-'.uniqid(),
        ownerUser: 'app_user',
    );

    mkdir($config->workingDirectory, 0755, true);
    $restoreTask->execute($config, new InMemoryBackupLogger);
});

test('execute passes forceDatabase flag to prepareForRestore', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')
        ->once()
        ->with('restored_db', Mockery::type(InMemoryBackupLogger::class), true);
    $mockHandler->shouldReceive('restore')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "echo 'fake restore'"));

    $mockProvider = Mockery::mock(DatabaseProvider::class);
    $mockProvider->shouldReceive('makeFromConfig')->once()->andReturn($mockHandler);

    setupDownloadMock();

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $config = new RestoreConfig(
        targetServer: buildTargetConfig(),
        snapshotVolume: buildSnapshotVolumeConfig(),
        snapshotFilename: 'backup.sql.gz',
        snapshotFileSize: 1024,
        snapshotCompressionType: CompressionType::GZIP,
        snapshotDatabaseType: DatabaseType::MYSQL,
        snapshotDatabaseName: 'sourcedb',
        schemaName: 'restored_db',
        workingDirectory: $this->tempDir.'/force-test-'.uniqid(),
        forceDatabase: true,
    );

    mkdir($config->workingDirectory, 0755, true);
    $restoreTask->execute($config, new InMemoryBackupLogger);
});

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
            null,
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

test('execute restores firebird snapshot and runs download/decompress/cleanup lifecycle', function () {
    $config = buildFirebirdRestoreConfig();
    mkdir($config->workingDirectory, 0755, true);

    $mockProvider = buildMockFirebirdRestoreProvider();
    setupFirebirdDownloadMock($config);

    $restoreTask = new RestoreTask(
        $mockProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $restoreTask->execute($config, new InMemoryBackupLogger);

    expect($this->shellProcessor->hasCommand(FIREBIRD_RESTORE_COMMAND))->toBeTrue()
        ->and(is_dir($config->workingDirectory))->toBeFalse();
});

test('execute propagates firebird restore command failure and cleans up working directory', function () {
    $config = buildFirebirdRestoreConfig();
    mkdir($config->workingDirectory, 0755, true);

    $mockProvider = buildMockFirebirdRestoreProvider();
    setupFirebirdDownloadMock($config);

    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->with(FIREBIRD_RESTORE_COMMAND)
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Firebird restore failed'));

    $compressor = Mockery::mock(\App\Services\Backup\Compressors\CompressorInterface::class);
    $compressor->shouldReceive('decompress')
        ->once()
        ->andReturnUsing(function (string $compressedFile) {
            $decompressedFile = preg_replace('/\.gz$/', '', $compressedFile);
            file_put_contents($decompressedFile, '-- Fake Firebird backup data');

            return $decompressedFile;
        });

    $compressorFactory = Mockery::mock(\App\Services\Backup\Compressors\CompressorFactory::class);
    $compressorFactory->shouldReceive('make')->once()->with(CompressionType::GZIP)->andReturn($compressor);

    $restoreTask = new RestoreTask(
        $mockProvider,
        $shellProcessor,
        $this->filesystemProvider,
        $compressorFactory,
        $this->sshTunnelService,
    );

    expect(fn () => $restoreTask->execute($config, new InMemoryBackupLogger))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class, 'Firebird restore failed');

    expect(is_dir($config->workingDirectory))->toBeFalse();
});
