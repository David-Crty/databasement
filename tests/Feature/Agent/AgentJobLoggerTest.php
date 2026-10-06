<?php

use App\Exceptions\Backup\JobRevokedException;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentJobLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function agentJobLogger(bool $serverMergesLogs = true): AgentJobLogger
{
    return new AgentJobLogger(new AgentApiClient('http://server.test', 'test-token'), 'job-1', $serverMergesLogs);
}

/**
 * The entries each heartbeat carried, as "message" or "command [status]".
 *
 * @return list<list<string>>
 */
function sentHeartbeats(): array
{
    return Http::recorded()->map(fn (array $pair) => collect($pair[0]['logs'])
        ->map(fn (array $entry) => $entry['message'] ?? "{$entry['command']} [{$entry['status']}]")
        ->all()
    )->values()->all();
}

test('each write is sent right away with the entries added or changed since the last send', function () {
    Http::fake();
    $logger = agentJobLogger();

    $logger->log('Starting backup');
    $index = $logger->startCommandLog('pg_dump app');
    $logger->updateCommandLog($index, ['output' => 'dumping']);
    $logger->updateCommandLog($index, ['status' => 'completed']);

    expect(sentHeartbeats())->toBe([
        ['Starting backup'],
        ['pg_dump app [running]'],
        ['pg_dump app [running]'],
        ['pg_dump app [completed]'],
    ])->and($logger->unsentLogs())->toBeEmpty();
});

test('a server that does not merge logs only receives a command once it is final', function () {
    Http::fake();
    $logger = agentJobLogger(serverMergesLogs: false);

    $index = $logger->startCommandLog('pg_dump app');
    $logger->updateCommandLog($index, ['output' => 'dumping']);
    $logger->updateCommandLog($index, ['status' => 'completed']);

    expect(sentHeartbeats())->toBe([[], [], ['pg_dump app [completed]']]);
});

test('a failed send never stops the job, and its entries go with the next one', function () {
    Log::spy();
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->push(['status' => 'ok'])]);
    $logger = agentJobLogger();

    $logger->log('Starting backup');
    $logger->log('Dump done');

    expect(sentHeartbeats())->toBe([['Starting backup'], ['Starting backup', 'Dump done']])
        ->and($logger->unsentLogs())->toBeEmpty();
});

test('a job the server revoked stops the job', function () {
    Http::fake(['*' => Http::response(['message' => 'Cannot heartbeat a job with status \'failed\'.'], 409)]);

    agentJobLogger()->log('Starting backup');
})->throws(JobRevokedException::class);
