<?php

use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentAuthenticationException;
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
});

describe('claimJob', function () {
    test('returns job data on success', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response([
                'job' => ['id' => 'job-1', 'snapshot_id' => 'snap-1', 'payload' => ['test' => true]],
            ]),
        ]);

        $result = $this->client->claimJob();

        expect($result)->toBe(['id' => 'job-1', 'snapshot_id' => 'snap-1', 'payload' => ['test' => true]]);
    });

    test('returns null when no jobs available', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response(['job' => null]),
        ]);

        expect($this->client->claimJob())->toBeNull();
    });

    test('returns null on failure response', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response('Server Error', 500),
        ]);

        expect($this->client->claimJob())->toBeNull();
    });

    test('throws authentication exception on 401/403 instead of masking it', function (int $status) {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response('Unauthorized', $status),
        ]);

        expect(fn () => $this->client->claimJob())->toThrow(AgentAuthenticationException::class);
    })->with([401, 403]);

    test('returns null when job payload is not an array', function () {
        Http::fake([
            'http://server.test/api/v1/agent/jobs/claim' => Http::response(['job' => 'unexpected-string']),
        ]);

        expect($this->client->claimJob())->toBeNull();
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

    test('sends empty payload when no logs', function () {
        Http::fake();

        $this->client->jobHeartbeat('job-1');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://server.test/api/v1/agent/jobs/job-1/heartbeat'
                && empty($request->data());
        });
    });
});

describe('ack', function () {
    test('sends all fields to ack endpoint', function () {
        Http::fake();
        $logs = [['timestamp' => '2026-01-01T00:00:00+00:00', 'type' => 'log', 'level' => 'success', 'message' => 'Done']];

        $this->client->ack('job-1', 'backup.sql.gz', 12345, 'sha256hash', $logs);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://server.test/api/v1/agent/jobs/job-1/ack'
                && $request['filename'] === 'backup.sql.gz'
                && $request['file_size'] === 12345
                && $request['checksum'] === 'sha256hash'
                && $request['logs'][0]['message'] === 'Done';
        });
    });
});

describe('upload', function () {
    test('streams the archive to the upload endpoint with metadata in the query string', function () {
        Http::fake();

        $tmp = tempnam(sys_get_temp_dir(), 'upload-test');
        file_put_contents($tmp, 'fake-archive-bytes');

        try {
            $this->client->upload('job-1', $tmp, 'backups/db.sql.gz', 'sha256hash', 18);
        } finally {
            @unlink($tmp);
        }

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://server.test/api/v1/agent/jobs/job-1/upload?')
                && str_contains($request->url(), 'filename='.urlencode('backups/db.sql.gz'))
                && str_contains($request->url(), 'checksum=sha256hash')
                && str_contains($request->url(), 'file_size=18')
                && $request->body() === 'fake-archive-bytes'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    });

    test('retries the upload on a transient server error', function () {
        Http::fake([
            '*/upload*' => Http::sequence()
                ->push('boom', 500)
                ->push(['status' => 'ok'], 200),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'upload-test');
        file_put_contents($tmp, 'data');

        try {
            $this->client->upload('job-1', $tmp, 'db.sql.gz', 'sum', 4);
        } finally {
            @unlink($tmp);
        }

        Http::assertSentCount(2);
    });

    test('does not retry the upload on a client error', function () {
        Http::fake(['*/upload*' => Http::response('rejected', 422)]);

        $tmp = tempnam(sys_get_temp_dir(), 'upload-test');
        file_put_contents($tmp, 'data');

        try {
            expect(fn () => $this->client->upload('job-1', $tmp, 'db.sql.gz', 'sum', 4))
                ->toThrow(\Illuminate\Http\Client\RequestException::class);
        } finally {
            @unlink($tmp);
        }

        Http::assertSentCount(1);
    });

    test('runs the before-attempt hook once per attempt (lease refresh)', function () {
        Http::fake([
            '*/upload*' => Http::sequence()
                ->push('boom', 500)
                ->push(['status' => 'ok'], 200),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'upload-test');
        file_put_contents($tmp, 'data');

        $attempts = 0;

        try {
            $this->client->upload('job-1', $tmp, 'db.sql.gz', 'sum', 4, function () use (&$attempts) {
                $attempts++;
            });
        } finally {
            @unlink($tmp);
        }

        expect($attempts)->toBe(2);
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
