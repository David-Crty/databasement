<?php

use App\Facades\AppConfig;
use App\Models\DatabaseServerSshConfig;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use League\Flysystem\Filesystem;
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

    // Create temp directory for test files and set config
    $this->tempDir = sys_get_temp_dir().'/backup-task-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
    AppConfig::set('backup.working_directory', $this->tempDir);
    AppConfig::set('backup.compression', 'gzip');
});

// Helper function to set up common expectations
function setupCommonExpectations(Snapshot $snapshot): void
{
    test()->filesystemProvider
        ->shouldReceive('getConfig')
        ->with('local', 'root')
        ->andReturn(test()->tempDir);

    test()->filesystemProvider
        ->shouldReceive('get')
        ->with($snapshot->databaseServer->backup->volume->type)
        ->andReturn(Mockery::mock(Filesystem::class));

    test()->filesystemProvider
        ->shouldReceive('transfer')
        ->once();
}

test('run executes backup workflow successfully', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(function (string $outputPath) {
            return new DatabaseOperationResult(command: "echo 'fake dump' > ".escapeshellarg($outputPath));
        });

    $mockFactory = Mockery::mock(DatabaseProvider::class);
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->andReturn($mockHandler);

    $backupTask = new BackupTask(
        $mockFactory,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService
    );

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
    $backupTask->run($snapshot);

    // Verify orchestration outcomes: snapshot completed with metadata
    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('completed')
        ->and($snapshot->filename)->not->toBeEmpty()
        ->and($snapshot->file_size)->toBeGreaterThan(0)
        ->and($snapshot->checksum)->not->toBeEmpty();
});

test('run throws exception when backup command failed', function () {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturn(new DatabaseOperationResult(command: "mysqldump --host='localhost' 'myapp'"));

    $mockFactory = Mockery::mock(DatabaseProvider::class);
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->andReturn($mockHandler);

    $shellProcessor = Mockery::mock(\App\Services\Backup\ShellProcessor::class);
    $shellProcessor->shouldReceive('setLogger')->once();
    $shellProcessor->shouldReceive('process')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    $backupTask = new BackupTask(
        $mockFactory,
        $shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService
    );

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

    expect(fn () => $backupTask->run($snapshot))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class, 'Access denied for user');

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('failed')
        ->and($snapshot->job->error_message)->toBe('Access denied for user')
        ->and($snapshot->job->completed_at)->not->toBeNull();
});

test('run executes backup for each database when backup_all_databases is enabled', function () {
    $mockDatabaseProvider = Mockery::mock(DatabaseProvider::class);
    $mockDatabaseProvider->shouldReceive('listDatabasesForServer')
        ->once()
        ->andReturn(['app_db', 'users_db']);

    $backupJobFactory = new BackupJobFactory($mockDatabaseProvider);
    $databaseProvider = new DatabaseProvider;

    $backupTask = new BackupTask(
        $databaseProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService
    );

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

    foreach ($snapshots as $snapshot) {
        $this->shellProcessor->clearCommands();
        setupCommonExpectations($snapshot);
        $backupTask->run($snapshot);
    }

    expect($snapshots)->toHaveCount(2);

    foreach ($snapshots as $snapshot) {
        $snapshot->refresh();
        expect($snapshot->job->status)->toBe('completed')
            ->and($snapshot->filename)->not->toBeEmpty()
            ->and($snapshot->file_size)->toBeGreaterThan(0);
    }
});

test('run handles backup path configuration correctly', function (?string $configuredPath, string $expectedPrefix) {
    $databaseProvider = new DatabaseProvider;

    $backupTask = new BackupTask(
        $databaseProvider,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $this->sshTunnelService
    );

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
    $backupTask->run($snapshot);

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
    'path with year variable' => ['backups/{year}', 'backups/'.now()->format('Y').'/'],
    'path with all date variables' => ['{year}/{month}/{day}', now()->format('Y').'/'.now()->format('m').'/'.now()->format('d').'/'],
    'path with mixed static and variables' => ['prod/{year}/{month}', 'prod/'.now()->format('Y').'/'.now()->format('m').'/'],
]);

test('run establishes SSH tunnel when server requires it', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create();

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

    // Configure SSH tunnel mock
    $sshTunnelService = Mockery::mock(SshTunnelService::class);
    $sshTunnelService->shouldReceive('establish')
        ->once()
        ->with(Mockery::on(fn ($server) => $server->id === $databaseServer->id))
        ->andReturn(['host' => '127.0.0.1', 'port' => 54321]);
    $sshTunnelService->shouldReceive('isActive')
        ->andReturn(true);
    $sshTunnelService->shouldReceive('close')
        ->once();

    // Mock factory to verify it receives tunnel endpoint
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('dump')
        ->once()
        ->andReturnUsing(fn (string $outputPath) => new DatabaseOperationResult(command: "echo 'fake dump' > ".escapeshellarg($outputPath)));

    $mockFactory = Mockery::mock(DatabaseProvider::class);
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->with(
            Mockery::on(fn ($server) => $server->id === $databaseServer->id),
            'myapp',
            '127.0.0.1',
            54321
        )
        ->andReturn($mockHandler);

    $backupTask = new BackupTask(
        $mockFactory,
        $this->shellProcessor,
        $this->filesystemProvider,
        $this->compressorFactory,
        $sshTunnelService
    );

    setupCommonExpectations($snapshot);
    $backupTask->run($snapshot);

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('completed');
});
