<?php

use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\JobRevokedException;
use App\Exceptions\Backup\VolumeTransferException;
use App\Services\Agent\AgentJobLogger;
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

    $this->agentStreams = ['stdout' => fopen('php://memory', 'w+'), 'stderr' => fopen('php://memory', 'w+')];
    config([
        'logging.channels.stdio_stdout.with.stream' => $this->agentStreams['stdout'],
        'logging.channels.stdio_stderr.with.stream' => $this->agentStreams['stderr'],
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

function agentStdout(): string
{
    return stream_get_contents(test()->agentStreams['stdout'], offset: 0);
}

function agentStderr(): string
{
    return stream_get_contents(test()->agentStreams['stderr'], offset: 0);
}

/**
 * Fake the server the agent talks to: a healthy heartbeat, a claim that hands
 * out $job, and the job endpoints in $jobRoutes (paths under /agent/jobs/),
 * answered with "ok" unless a response is given for them.
 *
 * @param  array<string, mixed>|null  $job
 * @param  array<int|string, mixed>  $jobRoutes
 */
function fakeAgentServer(?array $job, array $jobRoutes = []): void
{
    $routes = [];

    foreach ($jobRoutes as $path => $response) {
        if (is_int($path)) {
            [$path, $response] = [$response, Http::response(['status' => 'ok'])];
        }

        $routes["*/agent/jobs/{$path}"] = $response;
    }

    Http::fake([
        '*/agent/heartbeat' => Http::response(['status' => 'ok']),
        '*/agent/jobs/claim' => Http::response(['job' => $job]),
        ...$routes,
    ]);
}

test('fails when agent url and token are not configured', function () {
    config(['agent.url' => '', 'agent.token' => '']);

    $this->artisan('agent:run')->assertExitCode(1);

    expect(agentStderr())->toContain('DATABASEMENT_URL and DATABASEMENT_AGENT_TOKEN must be configured.');
});

test('exits cleanly when no jobs are available', function () {
    fakeAgentServer(null);

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStdout())->toContain('Databasement Agent starting...')->toContain('Agent stopped gracefully.');

    Http::assertSentCount(2);
});

test('processes a job and calls ack on success', function () {
    fakeAgentServer($this->jobPayload, ['job-123/ack']);

    $mockResult = new BackupResult('backup_testdb.sql.gz', 54321, 'abc123hash', [
        new VolumeTransferResult('vol-1', 'Test Volume', SnapshotFileStatus::Completed),
        new VolumeTransferResult('vol-2', 'Offsite', SnapshotFileStatus::Completed),
    ]);
    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()
        ->with(Mockery::any(), Mockery::type(AgentJobLogger::class))
        ->andReturn($mockResult);

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStdout())->toContain('Processing job job-123: prod-mysql / testdb')->toContain('Job completed: backup_testdb.sql.gz');

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

test('reports per-volume outcomes when only some uploads fail', function () {
    fakeAgentServer($this->jobPayload, ['job-123/fail']);

    $partialResult = new BackupResult('backup_testdb.sql.gz', 54321, 'abc123hash', [
        new VolumeTransferResult('vol-1', 'Test Volume', SnapshotFileStatus::Completed),
        new VolumeTransferResult('vol-2', 'Offsite', SnapshotFileStatus::Failed, 'S3 unreachable'),
    ]);

    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()
        ->andThrow(new VolumeTransferException($partialResult, 'Upload failed for volume(s): Offsite'));

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStderr())->toContain('Job failed: Upload failed for volume(s): Offsite');

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

    fakeAgentServer($legacyPayload, ['job-123/ack']);

    $capturedConfig = null;
    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()
        ->andReturnUsing(function (...$args) use (&$capturedConfig) {
            $capturedConfig = $args[0];

            return new BackupResult('backup_testdb.sql.gz', 54321, 'abc123hash', [
                new VolumeTransferResult(null, 'Test Volume', SnapshotFileStatus::Completed),
            ]);
        });

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStdout())->toContain('Job completed: backup_testdb.sql.gz');

    expect($capturedConfig->volumes)->toHaveCount(1)
        ->and($capturedConfig->volumes[0]->name)->toBe('Test Volume');
});

test('calls fail endpoint when backup task throws', function () {
    fakeAgentServer($this->jobPayload, ['job-123/fail']);

    $backupTask = $this->mock(BackupTask::class);
    $backupTask->shouldReceive('execute')->once()->andThrow(new RuntimeException('Connection refused'));

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStderr())->toContain('Job failed: Connection refused');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fail')
        && $request['error_message'] === 'Connection refused'
    );
});

test('a job the server revoked is stopped without reporting a failure', function () {
    fakeAgentServer($this->jobPayload, ['job-123/heartbeat']);

    $this->mock(BackupTask::class)->shouldReceive('execute')->once()
        ->andThrow(new JobRevokedException("Cannot heartbeat a job with status 'failed'."));

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStderr())->toContain("Job job-123 stopped: Cannot heartbeat a job with status 'failed'.");

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fail'));
});

test('tells the server when it is stopped by a signal while running a job', function () {
    fakeAgentServer($this->jobPayload, ['job-123/heartbeat', 'job-123/fail']);

    $this->mock(BackupTask::class)->shouldReceive('execute')->once()
        ->andReturnUsing(function () {
            posix_kill(getmypid(), SIGTERM);

            throw new RuntimeException('Killed');
        });

    $this->artisan('agent:run')->assertSuccessful();

    expect(agentStdout())->toContain('Agent stopped gracefully.');

    expect(agentStderr())->toContain('Agent received SIGTERM and is shutting down.');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/agent/jobs/job-123/heartbeat')
        && str_contains($request['logs'][0]['message'] ?? '', 'Agent received SIGTERM')
        && $request['logs'][0]['level'] === 'warning'
    );
})->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'Needs the pcntl and posix extensions.');

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

    $this->artisan('agent:run --once')->assertExitCode(1);

    expect(agentStderr())->toContain('Authentication failed. Please check your DATABASEMENT_AGENT_TOKEN.');
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

    fakeAgentServer($discoveryPayload, ['job-456/discovered-databases' => Http::response(['status' => 'ok', 'jobs_created' => 2])]);

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->once()->andReturn(['app_db', 'analytics_db']);
    });

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStdout())->toContain('Processing discovery job job-456: prod-mysql')->toContain('Discovery completed: 2 database(s) found');

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

    fakeAgentServer($discoveryPayload, ['job-789/discovered-databases' => Http::response(['status' => 'ok', 'jobs_created' => 2])]);

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
        fakeAgentServer($this->restoreJob, ['job-789/ack']);

        $this->mock(RestoreTask::class)->shouldReceive('execute')->once()
            ->withArgs(fn (RestoreConfig $config, $logger) => $logger instanceof AgentJobLogger
                && $config->schemaName === 'restored_db'
                && $config->snapshotVolume->type === 's3'
                && $config->targetServer->serverName === 'staging-mysql');

        $this->artisan('agent:run --once')->assertSuccessful();

        expect(agentStdout())->toContain('Processing restore job job-789: staging-mysql / restored_db')->toContain('Restore completed: restored_db');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/claim')
            && in_array('restore', $request['job_types'], true));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-789/ack')
            && ! isset($request['filename']));
    });

    test('reports a failed restore', function () {
        fakeAgentServer($this->restoreJob, ['job-789/fail']);

        $this->mock(RestoreTask::class)->shouldReceive('execute')->once()
            ->andThrow(new RuntimeException('Access denied for user'));

        $this->artisan('agent:run --once')->assertSuccessful();

        expect(agentStderr())->toContain('Restore failed: Access denied for user');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-789/fail')
            && $request['error_message'] === 'Access denied for user');
    });
});

test('fails a job whose type this agent cannot run', function () {
    fakeAgentServer([...$this->jobPayload, 'type' => 'teleport'], ['job-123/fail']);

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(agentStderr())->toContain("Job job-123 has unsupported type 'teleport'.");

    Http::assertSent(fn ($request) => str_contains($request->url(), '/jobs/job-123/fail')
        && str_contains($request['error_message'], 'Update the agent'));
});

test('a cleanup job deletes the file and reports an already absent one as removed', function () {
    $present = sys_get_temp_dir().'/cleanup-'.uniqid();
    $absent = sys_get_temp_dir().'/cleanup-'.uniqid();
    mkdir($present.'/2026', 0755, true);
    mkdir($absent);
    file_put_contents($present.'/2026/backup.sql.gz', 'dump');

    fakeAgentServer([
        'id' => 'job-123',
        'type' => 'cleanup',
        'payload' => [
            'filename' => '2026/backup.sql.gz',
            'server_name' => 'prod-mysql',
            'files' => [
                ['id' => 'file-1', 'volume' => ['type' => 'local', 'name' => 'Present', 'config' => ['path' => $present]]],
                ['id' => 'file-2', 'volume' => ['type' => 'local', 'name' => 'Absent', 'config' => ['path' => $absent]]],
            ],
        ],
    ], ['job-123/ack']);

    $this->artisan('agent:run --once')->assertSuccessful();

    expect(file_exists($present.'/2026'))->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/ack')
        && $request['files'] === [['id' => 'file-1', 'error' => null], ['id' => 'file-2', 'error' => null]]);
});

test('a cleanup job reports the volumes it could not delete from as failed', function () {
    fakeAgentServer([
        'id' => 'job-123',
        'type' => 'cleanup',
        'payload' => [
            'filename' => 'backup.sql.gz',
            'server_name' => 'prod-mysql',
            'files' => [
                ['id' => 'file-1', 'volume' => ['type' => 'unsupported', 'name' => 'Broken', 'config' => []]],
            ],
        ],
    ], ['job-123/fail']);

    $this->artisan('agent:run --once')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fail')
        && str_contains($request['error_message'], 'Broken')
        && $request['files'][0]['id'] === 'file-1'
        && $request['files'][0]['error'] !== null);
});
