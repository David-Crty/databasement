<?php

use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\VolumeTransferException;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\RestoreConfig;
use App\Services\Backup\DTO\VolumeTransferResult;
use App\Services\Backup\RestoreTask;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'agent.url' => 'http://server.test',
        'agent.token' => 'test-token',
        'agent.poll_interval' => 5,
    ]);

    $this->jobPayload = [
        'id' => 'job-123',
        'snapshot_id' => 'snap-456',
        'payload' => [
            'database' => [
                'type' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => 'secret',
                'extra_config' => null,
                'database_name' => 'testdb',
            ],
            'volumes' => [
                [
                    'type' => 'local',
                    'name' => 'Test Volume',
                    'config' => ['path' => '/backups'],
                ],
                [
                    'type' => 's3',
                    'name' => 'Offsite',
                    'config' => ['bucket' => 'backups'],
                ],
            ],
            'compression' => ['type' => null, 'level' => null],
            'backup_path' => '',
            'server_name' => 'prod-mysql',
        ],
        'attempts' => 1,
        'max_attempts' => 3,
    ];
});

test('fails when agent url and token are not configured', function () {
    config(['agent.url' => '', 'agent.token' => '']);

    $this->artisan('agent:run')
        ->expectsOutputToContain('DATABASEMENT_URL and DATABASEMENT_AGENT_TOKEN must be configured.')
        ->assertExitCode(1);
});

test('exits cleanly when no jobs are available', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => null]),
    ]);

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Databasement Agent starting...')
        ->expectsOutputToContain('Agent stopped gracefully.')
        ->assertSuccessful();

    Http::assertSentCount(2);
});

test('processes a job and calls ack on success', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $this->jobPayload]),
        '*/agent/jobs/job-123/ack' => Http::response(['status' => 'ok']),
    ]);

    $mockResult = new BackupResult('backup_testdb.sql.gz', 54321, 'abc123hash', [
        new VolumeTransferResult('vol-1', 'Test Volume', SnapshotFileStatus::Completed),
        new VolumeTransferResult('vol-2', 'Offsite', SnapshotFileStatus::Completed),
    ]);
    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()->andReturn($mockResult);

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Processing job job-123: prod-mysql / testdb')
        ->expectsOutputToContain('Job completed: backup_testdb.sql.gz')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ack')
        && $request['filename'] === 'backup_testdb.sql.gz'
        && $request['file_size'] === 54321
        && $request['checksum'] === 'abc123hash'
        && count($request['volumes']) === 2
        && $request['volumes'][0]['volume_id'] === 'vol-1'
        && $request['volumes'][1]['volume_id'] === 'vol-2'
        && $request['volumes'][1]['status'] === SnapshotFileStatus::Completed->value
    );
});

test('a backup reports its running commands to the server', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $this->jobPayload]),
        '*/agent/jobs/job-123/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/job-123/ack' => Http::response(['status' => 'ok']),
    ]);

    $this->mock(BackupTask::class)->shouldReceive('execute')->once()
        ->andReturnUsing(function ($config, $logger, $onProgress, Closure $onCommandHeartbeat) {
            $onCommandHeartbeat(true);

            return new BackupResult('backup_testdb.sql.gz', 1, 'abc123hash');
        });

    $this->artisan('agent:run --once')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-123/heartbeat')
        && $request['in_command'] === true);
});

test('reports per-volume outcomes when only some uploads fail', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $this->jobPayload]),
        '*/agent/jobs/job-123/fail' => Http::response(['status' => 'ok']),
    ]);

    $partialResult = new BackupResult('backup_testdb.sql.gz', 54321, 'abc123hash', [
        new VolumeTransferResult('vol-1', 'Test Volume', SnapshotFileStatus::Completed),
        new VolumeTransferResult('vol-2', 'Offsite', SnapshotFileStatus::Failed, 'S3 unreachable'),
    ]);

    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()
        ->andThrow(new VolumeTransferException($partialResult, 'Upload failed for volume(s): Offsite'));

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Job failed: Upload failed for volume(s): Offsite')
        ->assertSuccessful();

    // The successful copy must still reach the app, alongside the failure.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/fail')
        && $request['error_message'] === 'Upload failed for volume(s): Offsite'
        && $request['filename'] === 'backup_testdb.sql.gz'
        && $request['file_size'] === 54321
        && $request['volumes'][0]['status'] === SnapshotFileStatus::Completed->value
        && $request['volumes'][1]['status'] === SnapshotFileStatus::Failed->value
        && $request['volumes'][1]['error'] === 'S3 unreachable'
    );
});

test('processes a legacy single-volume job payload', function () {
    // Agents can still claim jobs queued before multi-volume support, which
    // carry only the singular `volume` key.
    $legacyPayload = $this->jobPayload;
    unset($legacyPayload['payload']['volumes']);
    $legacyPayload['payload']['volume'] = [
        'type' => 'local',
        'name' => 'Test Volume',
        'config' => ['path' => '/backups'],
    ];

    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $legacyPayload]),
        '*/agent/jobs/job-123/ack' => Http::response(['status' => 'ok']),
    ]);

    $capturedConfig = null;
    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()
        ->andReturnUsing(function (...$args) use (&$capturedConfig) {
            $capturedConfig = $args[0];

            return new BackupResult('backup_testdb.sql.gz', 54321, 'abc123hash', [
                new VolumeTransferResult(null, 'Test Volume', SnapshotFileStatus::Completed),
            ]);
        });

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Job completed: backup_testdb.sql.gz')
        ->assertSuccessful();

    expect($capturedConfig->volumes)->toHaveCount(1)
        ->and($capturedConfig->volumes[0]->name)->toBe('Test Volume');
});

test('calls fail endpoint when backup task throws', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $this->jobPayload]),
        '*/agent/jobs/job-123/fail' => Http::response(['status' => 'ok']),
    ]);

    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()->andThrow(new RuntimeException('Connection refused'));

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Job failed: Connection refused')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fail')
        && $request['error_message'] === 'Connection refused'
    );
});

test('handles http errors during polling gracefully', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response('Server Error', 500),
    ]);

    $this->artisan('agent:run --once')
        ->assertSuccessful();
});

test('exits with failure on authentication error', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response('Unauthenticated', 401),
    ]);

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Authentication failed. Please check your DATABASEMENT_AGENT_TOKEN.')
        ->assertExitCode(1);
});

test('processes a discovery job and reports databases', function () {
    $discoveryPayload = [
        'id' => 'job-456',
        'snapshot_id' => null,
        'payload' => [
            'type' => 'discover',
            'database' => [
                'type' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => 'secret',
                'extra_config' => null,
            ],
            'selection_mode' => 'all',
            'pattern' => null,
            'server_name' => 'prod-mysql',
            'method' => 'manual',
            'triggered_by_user_id' => null,
        ],
        'attempts' => 1,
        'max_attempts' => 3,
    ];

    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $discoveryPayload]),
        '*/agent/jobs/job-456/discovered-databases' => Http::response(['status' => 'ok', 'jobs_created' => 2]),
    ]);

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->once()->andReturn(['app_db', 'analytics_db']);
    });

    $this->artisan('agent:run --once')
        ->expectsOutputToContain('Processing discovery job job-456: prod-mysql')
        ->expectsOutputToContain('Discovery completed: 2 database(s) found')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/discovered-databases')
        && $request['databases'] === ['app_db', 'analytics_db']
    );
});

test('discovery job with pattern filters databases', function () {
    $discoveryPayload = [
        'id' => 'job-789',
        'snapshot_id' => null,
        'payload' => [
            'type' => 'discover',
            'database' => [
                'type' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => 'secret',
                'extra_config' => null,
            ],
            'selection_mode' => 'pattern',
            'pattern' => '^prod_',
            'server_name' => 'prod-mysql',
            'method' => 'manual',
            'triggered_by_user_id' => null,
        ],
        'attempts' => 1,
        'max_attempts' => 3,
    ];

    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $discoveryPayload]),
        '*/agent/jobs/job-789/discovered-databases' => Http::response(['status' => 'ok', 'jobs_created' => 2]),
    ]);

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->once()
            ->andReturn(['prod_users', 'prod_orders', 'test_db', 'staging_db']);
    });

    $this->artisan('agent:run --once')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/discovered-databases')
        && $request['databases'] === ['prod_users', 'prod_orders']
    );
});

describe('restore jobs', function () {
    beforeEach(function () {
        $this->restoreJob = [
            'id' => 'job-789',
            'type' => 'restore',
            'snapshot_id' => null,
            'payload' => (new \App\Services\Backup\DTO\RestoreConfig(
                targetServer: new \App\Services\Backup\DTO\DatabaseConnectionConfig(
                    databaseType: \App\Enums\DatabaseType::MYSQL,
                    serverName: 'staging-mysql',
                    host: '127.0.0.1',
                    port: 3306,
                    username: 'root',
                    password: 'secret',
                ),
                snapshotVolume: new \App\Services\Backup\DTO\VolumeConfig('s3', 'Offsite', ['bucket' => 'backups']),
                snapshotFilename: 'backup_testdb.sql.gz',
                snapshotFileSize: 1024,
                snapshotCompressionType: \App\Enums\CompressionType::GZIP,
                snapshotDatabaseType: \App\Enums\DatabaseType::MYSQL,
                snapshotDatabaseName: 'testdb',
                schemaName: 'restored_db',
                workingDirectory: '',
            ))->toPayload(),
            'attempts' => 1,
            'max_attempts' => 1,
        ];
    });

    test('advertises restore support and acknowledges a completed restore', function () {
        Http::fake([
            '*/agent/heartbeat' => Http::response(['status' => 'ok']),
            '*/agent/jobs/claim' => Http::response(['job' => $this->restoreJob]),
            '*/agent/jobs/job-789/ack' => Http::response(['status' => 'ok']),
        ]);

        $this->mock(RestoreTask::class)->shouldReceive('execute')->once()
            ->withArgs(fn (RestoreConfig $config) => $config->schemaName === 'restored_db'
                && $config->snapshotVolume->type === 's3'
                && $config->targetServer->serverName === 'staging-mysql');

        $this->artisan('agent:run --once')
            ->expectsOutputToContain('Processing restore job job-789: staging-mysql / restored_db')
            ->expectsOutputToContain('Restore completed: restored_db')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/claim')
            && in_array('restore', $request['job_types'], true));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-789/ack')
            && ! isset($request['filename']));
    });

    test('a restore reports its running commands to the server', function () {
        Http::fake([
            '*/agent/heartbeat' => Http::response(['status' => 'ok']),
            '*/agent/jobs/claim' => Http::response(['job' => $this->restoreJob]),
            '*/agent/jobs/job-789/heartbeat' => Http::response(['status' => 'ok']),
            '*/agent/jobs/job-789/ack' => Http::response(['status' => 'ok']),
        ]);

        $this->mock(RestoreTask::class)->shouldReceive('execute')->once()
            ->andReturnUsing(fn ($config, $logger, $onProgress, Closure $onCommandHeartbeat) => $onCommandHeartbeat(true));

        $this->artisan('agent:run --once')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-789/heartbeat')
            && $request['in_command'] === true);
    });

    test('reports a failed restore', function () {
        Http::fake([
            '*/agent/heartbeat' => Http::response(['status' => 'ok']),
            '*/agent/jobs/claim' => Http::response(['job' => $this->restoreJob]),
            '*/agent/jobs/job-789/fail' => Http::response(['status' => 'ok']),
        ]);

        $this->mock(RestoreTask::class)->shouldReceive('execute')->once()
            ->andThrow(new RuntimeException('Access denied for user'));

        $this->artisan('agent:run --once')
            ->expectsOutputToContain('Restore failed: Access denied for user')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-789/fail')
            && $request['error_message'] === 'Access denied for user');
    });
});

test('fails a job whose type this agent cannot run', function () {
    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => [...$this->jobPayload, 'type' => 'cleanup']]),
        '*/agent/jobs/job-123/fail' => Http::response(['status' => 'ok']),
    ]);

    $this->artisan('agent:run --once')
        ->expectsOutputToContain("Job job-123 has unsupported type 'cleanup'.")
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-123/fail')
        && str_contains($request['error_message'], 'Update the agent'));
});
