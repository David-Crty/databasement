<?php

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Models\DatabaseServerSshConfig;
use App\Models\Restore;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Databases\DatabaseFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\RestoreTask;
use App\Services\SshTunnelService;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    $this->compressorFactory = new CompressorFactory($this->shellProcessor);

    // Mock external dependencies only
    $this->filesystemProvider = Mockery::mock(FilesystemProvider::class);
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    $this->sshTunnelService->shouldReceive('isActive')->andReturn(false);

    // Use real BackupJobFactory from container
    $this->backupJobFactory = app(BackupJobFactory::class);

    // Create temp directory for test files and set it as the backup tmp folder
    $this->tempDir = sys_get_temp_dir().'/restore-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    AppConfig::set('backup.working_directory', $this->tempDir);
});

// Helper to set up download mock and create a RestoreTask with mocked DatabaseFactory
function setupRestoreWithMockedFactory(Restore $restore, DatabaseInterface $mockHandler): RestoreTask
{
    // Mock download
    test()->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->with($restore->snapshot, Mockery::any())
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });

    // Mock prepareForRestore on handler
    $mockHandler->shouldReceive('prepareForRestore')
        ->once()
        ->andReturnNull();

    $mockFactory = Mockery::mock(DatabaseFactory::class);
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->andReturn($mockHandler);

    return new RestoreTask(
        $mockFactory,
        test()->shellProcessor,
        test()->filesystemProvider,
        test()->compressorFactory,
        test()->sshTunnelService,
    );
}

test('run executes restore workflow successfully', function () {
    $sourceServer = createDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'target.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'compression_type' => CompressionType::GZIP]);
    $snapshot->job->markCompleted();

    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    // Mock handler
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('getRestoreCommandLine')
        ->once()
        ->andReturn("echo 'fake restore'");

    $restoreTask = setupRestoreWithMockedFactory($restore, $mockHandler);
    $restoreTask->run($restore);

    // Verify orchestration: job completed
    $restore->refresh();
    expect($restore->job->status)->toBe('completed');
});

test('run throws exception when database types are incompatible', function () {
    $restoreTask = new RestoreTask(
        new DatabaseFactory,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService,
    );

    $sourceServer = createDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createDatabaseServer([
        'name' => 'Target PostgreSQL',
        'host' => 'localhost',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'postgres',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'compression_type' => CompressionType::GZIP]);
    $snapshot->job->markCompleted();

    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    expect(fn () => $restoreTask->run($restore))
        ->toThrow(\App\Exceptions\Backup\RestoreException::class, 'Cannot restore mysql snapshot to postgres server');
});

test('run throws exception when restore command failed', function () {
    $sourceServer = createDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'target.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['targetdb'],
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'compression_type' => CompressionType::GZIP]);
    $snapshot->job->markCompleted();

    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    // Shell processor that fails on restore command
    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    // Mock compressor to skip decompression and simulate decompressed file
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

    // Mock handler
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('getRestoreCommandLine')->once()->andReturn("mysql 'restored_db'");

    $mockFactory = Mockery::mock(DatabaseFactory::class);
    $mockFactory->shouldReceive('makeForServer')->once()->andReturn($mockHandler);

    $restoreTask = new RestoreTask(
        $mockFactory,
        $shellProcessor,
        $this->filesystemProvider,
        $compressorFactory,
        $this->sshTunnelService,
    );

    // Mock download
    $this->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });

    expect(fn () => $restoreTask->run($restore))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class, 'Access denied for user');

    $restore->refresh();
    expect($restore->job->status)->toBe('failed')
        ->and($restore->job->error_message)->toBe('Access denied for user')
        ->and($restore->job->completed_at)->not->toBeNull();
});

test('run establishes SSH tunnel when target server requires it', function () {
    $sourceServer = createDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $sshConfig = DatabaseServerSshConfig::create([
        'host' => 'bastion.example.com',
        'port' => 22,
        'username' => 'tunnel_user',
        'auth_type' => 'password',
        'password' => 'ssh_secret',
    ]);

    $targetServer = createDatabaseServer([
        'name' => 'Target MySQL via SSH',
        'host' => 'private-db.internal',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['targetdb'],
        'ssh_config_id' => $sshConfig->id,
    ]);

    $snapshots = $this->backupJobFactory->createSnapshots($sourceServer, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'compression_type' => CompressionType::GZIP]);
    $snapshot->job->markCompleted();

    $restore = $this->backupJobFactory->createRestore($snapshot, $targetServer, 'restored_db');

    // Configure SSH tunnel mock
    $sshTunnelService = Mockery::mock(SshTunnelService::class);
    $sshTunnelService->shouldReceive('establish')
        ->once()
        ->with(Mockery::on(fn ($server) => $server->id === $targetServer->id))
        ->andReturn(['host' => '127.0.0.1', 'port' => 54321]);
    $sshTunnelService->shouldReceive('isActive')
        ->andReturn(true);
    $sshTunnelService->shouldReceive('close')
        ->once();

    // Mock factory to verify it receives tunnel endpoint
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('prepareForRestore')->once()->andReturnNull();
    $mockHandler->shouldReceive('getRestoreCommandLine')
        ->once()
        ->andReturn("echo 'fake restore'");

    $mockFactory = Mockery::mock(DatabaseFactory::class);
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->with(
            Mockery::on(fn ($server) => $server->id === $targetServer->id),
            'restored_db',
            '127.0.0.1',
            54321
        )
        ->andReturn($mockHandler);

    $restoreTask = new RestoreTask(
        $mockFactory,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $sshTunnelService,
    );

    // Mock download
    $this->filesystemProvider
        ->shouldReceive('download')
        ->once()
        ->andReturnUsing(function ($snap, $destination) {
            file_put_contents($destination, 'compressed backup data');
        });

    $restoreTask->run($restore);

    $restore->refresh();
    expect($restore->job->status)->toBe('completed');
});
