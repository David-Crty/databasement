<?php

use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\JobRevokedException;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentAuthenticationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new AgentApiClient('http://server.test', 'test-token');
});

describe('heartbeat', function () {
    test('sends POST to heartbeat endpoint with token', function () {
        Http::fake();

        $this->client->heartbeat();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://server.test/api/v1/agent/heartbeat'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request->method() === 'POST';
        });
    });

    test('sends the version and commit headers', function (?string $version, string $expected) {
        config(['app.version' => $version, 'app.commit_hash' => 'a1b2c3d4e5']);
        Http::fake();

        $this->client->heartbeat();

        Http::assertSent(fn ($request) => $request->hasHeader(AgentApiClient::VERSION_HEADER, $expected)
            && $request->hasHeader(AgentApiClient::COMMIT_HEADER, 'a1b2c3d4e5'));
    })->with([
        'tagged build' => ['1.9.2', '1.9.2'],
        'untagged build' => [null, 'dev'],
    ]);
});

describe('claimJob', function () {
    test('returns job data on success', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response([
                'job' => ['id' => 'job-1', 'snapshot_id' => 'snap-1', 'payload' => ['test' => true]],
            ]),
        ]);

        $result = $this->client->claimJob(['backup']);

        expect($result)->toBe(['id' => 'job-1', 'snapshot_id' => 'snap-1', 'payload' => ['test' => true]]);
    });

    test('returns null when no jobs available', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response(['job' => null]),
        ]);

        expect($this->client->claimJob(['backup']))->toBeNull();
    });

    test('returns null on failure response', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response('Server Error', 500),
        ]);

        expect($this->client->claimJob(['backup']))->toBeNull();
    });

    test('throws authentication exception on 401/403 instead of masking it', function (int $status) {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response('Unauthorized', $status),
        ]);

        expect(fn () => $this->client->claimJob(['backup']))->toThrow(AgentAuthenticationException::class);
    })->with([401, 403]);

    test('returns null when job payload is not an array', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response(['job' => 'unexpected-string']),
        ]);

        expect($this->client->claimJob(['backup']))->toBeNull();
    });
});

describe('jobHeartbeat', function () {
    test('sends logs with heartbeat', function () {
        Http::fake();
        $logs = [
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'type' => 'log', 'level' => 'info', 'message' => 'Dump done'],
        ];

        $this->client->jobHeartbeat('job-1', $logs);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://server.test/api/v1/agent/jobs/job-1/heartbeat'
                && $request['logs'][0]['message'] === 'Dump done';
        });
    });

    test('a job the server rejects is revoked', function (int $status) {
        Http::fake(['*' => Http::response(['message' => 'Rejected'], $status)]);

        $this->client->jobHeartbeat('job-1');
    })->with([
        'token revoked' => [401],
        'job reassigned' => [403],
        'job deleted' => [404],
        'job failed' => [409],
    ])->throws(JobRevokedException::class);

    test('any other failure is thrown as is', function () {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 503)]);

        $this->client->jobHeartbeat('job-1');
    })->throws(RequestException::class);
});

describe('ack', function () {
    test('sends the result fields flat alongside the logs', function () {
        Http::fake();
        $logs = [['timestamp' => '2026-01-01T00:00:00+00:00', 'type' => 'log', 'level' => 'success', 'message' => 'Done']];

        $volumeResults = [['volume_id' => 'vol-1', 'volume_name' => 'Local', 'status' => SnapshotFileStatus::Completed->value, 'error' => null, 'storage_warning' => null, 'quota_exceeded' => false]];

        $this->client->ack('job-1', [
            'filename' => 'backup.sql.gz',
            'file_size' => 12345,
            'checksum' => 'sha256hash',
            'volumes' => $volumeResults,
        ], $logs);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://server.test/api/v1/agent/jobs/job-1/ack'
                && $request['filename'] === 'backup.sql.gz'
                && $request['file_size'] === 12345
                && $request['checksum'] === 'sha256hash'
                && $request['volumes'][0]['volume_id'] === 'vol-1'
                && $request['volumes'][0]['status'] === SnapshotFileStatus::Completed->value
                && $request['logs'][0]['message'] === 'Done';
        });
    });
});

describe('fail', function () {
    test('sends error message and logs to fail endpoint', function () {
        Http::fake();
        $logs = [['timestamp' => '2026-01-01T00:00:00+00:00', 'type' => 'log', 'level' => 'error', 'message' => 'Failed']];

        $this->client->fail('job-1', 'Connection refused', $logs);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://server.test/api/v1/agent/jobs/job-1/fail'
                && $request['error_message'] === 'Connection refused'
                && $request['logs'][0]['message'] === 'Failed';
        });
    });

    test('truncates long error messages', function () {
        Http::fake();
        $longMessage = str_repeat('x', 20000);

        $this->client->fail('job-1', $longMessage);

        Http::assertSent(function ($request) {
            return strlen($request['error_message']) <= 10000;
        });
    });
});
