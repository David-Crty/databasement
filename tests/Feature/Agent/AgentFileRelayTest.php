<?php

use App\Enums\AgentJobType;
use App\Enums\BackupJobStatus;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentJobPayloadBuilder;
use App\Services\Agent\Relay\RelayVolumes;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A backup agent job for a server of $agent, targeting $volumes.
 */
function relayBackupJob(Agent $agent, Volume ...$volumes): AgentJob
{
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
    $snapshot = Snapshot::factory()->forServer($server)->onVolumes(...$volumes)->create();

    return AgentJob::enqueue(
        AgentJobType::Backup,
        $server->id,
        app(AgentJobPayloadBuilder::class)->buildBackup($snapshot->fresh()),
        ['snapshot_id' => $snapshot->id],
    );
}

/**
 * Claim the next job as $agent through the API, as an agent that can relay
 * unless told otherwise, and return the job it was handed.
 *
 * @return array<string, mixed>|null
 */
function claimThroughApi(Agent $agent, bool $supportsRelay = true): ?array
{
    return test()->withToken($agent->createToken('agent')->plainTextToken)
        ->postJson('/api/v1/agent/jobs/claim', [
            'job_types' => ['backup', 'restore', 'cleanup'],
            'capabilities' => $supportsRelay ? [RelayVolumes::CAPABILITY] : [],
        ])
        ->assertOk()
        ->json('job');
}

/**
 * The filesystem an agent gets for a payload volume, with its HTTP calls
 * answered by this app, as if the agent talked to it over the network.
 *
 * @param  array<string, mixed>  $volumePayload
 */
function agentFilesystem(Agent $agent, array $volumePayload): Filesystem
{
    $token = $agent->createToken('agent')->plainTextToken;

    Http::fake(function (ClientRequest $request) {
        $response = test()->call(
            $request->method(),
            $request->url(),
            server: [
                'HTTP_AUTHORIZATION' => $request->header('Authorization')[0],
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => $request->header('Content-Type')[0] ?? 'application/json',
            ],
            content: $request->body(),
        );

        return Http::response(
            $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent(),
            $response->status(),
        );
    });

    app()->instance(AgentApiClient::class, new AgentApiClient('http://databasement.test', $token));

    return app(FilesystemProvider::class)->getForVolumeConfig(VolumeConfig::fromPayload($volumePayload));
}

describe('claim', function () {
    test('an agent transferring through the server receives relay volumes without storage credentials', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $s3 = Volume::factory()->s3()->create();
        relayBackupJob($agent, $s3);

        $job = claimThroughApi($agent);

        expect($job['payload']['volumes'])->toBe([[
            'id' => $s3->id,
            'type' => RelayVolumes::VOLUME_TYPE,
            'name' => $s3->name,
            'config' => ['job_id' => $job['id'], 'volume_id' => $s3->id],
        ]])
            ->and($job['payload']['volume']['type'])->toBe(RelayVolumes::VOLUME_TYPE)
            ->and(json_encode($job))->not->toContain($s3->getDecryptedConfig()['secret_access_key']);
    });

    test('an agent transferring directly reaches the app\'s local volumes through the server and the others directly', function () {
        $agent = Agent::factory()->create();
        $local = Volume::factory()->local()->create();
        $s3 = Volume::factory()->s3()->create();
        relayBackupJob($agent, $local, $s3);

        $volumes = collect(claimThroughApi($agent)['payload']['volumes'])->keyBy('id');

        expect($volumes[$local->id]['type'])->toBe(RelayVolumes::VOLUME_TYPE)
            ->and($volumes[$s3->id]['type'])->toBe('s3');
    });

    test('an agent too old to relay fails the job instead of receiving it', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $agentJob = relayBackupJob($agent, Volume::factory()->s3()->create());

        expect(claimThroughApi($agent, supportsRelay: false))->toBeNull();

        expect($agentJob->fresh())
            ->status->toBe(AgentJob::STATUS_FAILED)
            ->error_message->toContain('Update the agent')
            ->and($agentJob->snapshot->job->fresh()->status)->toBe(BackupJobStatus::Failed);
    });
});

describe('file transfers', function () {
    test('a backup uploads its archive in chunks to the real volume', function () {
        config(['agent.relay_chunk_bytes' => 4]);
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $job = claimThroughApi($agent);
        $path = trim($agentJob->payload['backup_path'], '/').'/archive.sql.gz';

        agentFilesystem($agent, $job['payload']['volumes'][0])->write($path, 'dumped database');

        expect(file_get_contents($volume->config['path'].'/'.$path))->toBe('dumped database')
            ->and(Http::recorded())->toHaveCount(5); // 4 chunks + complete
    });

    test('a chunk sent at the wrong offset is told where the upload stands', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);
        $url = "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volume->id}/upload?".http_build_query([
            'path' => trim($agentJob->payload['backup_path'], '/').'/archive.sql.gz',
        ]);

        $this->withToken($token)->call('PUT', $url.'&offset=0', content: 'abcd')->assertOk();

        $this->withToken($token)->call('PUT', $url.'&offset=10', content: 'efgh')
            ->assertConflict()
            ->assertJsonPath('received', 4);
    });

    test('a restore downloads its source copy through the server', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        ['agentJob' => $agentJob, 'restore' => $restore] = dispatchAgentRestore($agent, Volume::factory()->local()->create());
        $sourceFile = $restore->snapshot->files()->with('volume')->sole();
        $sourcePath = $sourceFile->volume->config['path'].'/'.$sourceFile->storedFilename();
        @mkdir(dirname($sourcePath), 0755, true);
        file_put_contents($sourcePath, 'snapshot data');

        $job = claimThroughApi($agent);

        expect($job['id'])->toBe($agentJob->id)
            ->and(agentFilesystem($agent, $job['payload']['volume'])->read($job['payload']['snapshot']['filename']))->toBe('snapshot data');
    });

    test('a cleanup deletes the copy through the server', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
        $snapshot = Snapshot::factory()->forServer($server)->onVolumes($volume)->withFile()->create();
        AgentJob::enqueue(AgentJobType::Cleanup, $server->id, app(AgentJobPayloadBuilder::class)->buildCleanup($snapshot, $snapshot->files()->with('volume')->get()), ['snapshot_id' => $snapshot->id]);

        $job = claimThroughApi($agent);

        expect(FilesystemSupport::deleteFile(agentFilesystem($agent, $job['payload']['files'][0]['volume']), $snapshot->filename))->toBeTrue()
            ->and(file_exists($volume->config['path'].'/'.$snapshot->filename))->toBeFalse();
    });

    test('a job only reaches the files it was created for', function (Closure $request) {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);

        $request($this->withToken($token), "/api/v1/agent/jobs/{$agentJob->id}/volumes", $volume, $agentJob)->assertForbidden();
    })->with([
        'writing outside the backup path' => fn () => fn ($http, $base, $volume) => $http->call('PUT', "{$base}/{$volume->id}/upload?path=elsewhere/archive.gz&offset=0", content: 'x'),
        'climbing out of the volume' => fn () => fn ($http, $base, $volume, $job) => $http->call('PUT', "{$base}/{$volume->id}/upload?".http_build_query(['path' => trim($job->payload['backup_path'], '/').'/../../etc/passwd', 'offset' => 0]), content: 'x'),
        'reading as a backup' => fn () => fn ($http, $base, $volume, $job) => $http->get("{$base}/{$volume->id}/file?".http_build_query(['path' => trim($job->payload['backup_path'], '/').'/archive.gz'])),
        'a volume of another job' => fn () => fn ($http, $base, $volume, $job) => $http->call('PUT', "{$base}/".Volume::factory()->local()->create()->id.'/upload?'.http_build_query(['path' => trim($job->payload['backup_path'], '/').'/archive.gz', 'offset' => 0]), content: 'x'),
    ]);

    test('an agent transferring directly cannot relay its other volumes', function () {
        $agent = Agent::factory()->create();
        $s3 = Volume::factory()->s3()->create();
        $agentJob = relayBackupJob($agent, $s3);
        $token = claimAsAgent($agent, $agentJob);

        $this->withToken($token)
            ->call('PUT', "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$s3->id}/upload?".http_build_query([
                'path' => trim($agentJob->payload['backup_path'], '/').'/archive.gz',
                'offset' => 0,
            ]), content: 'x')
            ->assertForbidden();
    });
});

describe('local volumes', function () {
    test('a restore by an agent transferring through the server reads the local copy', function () {
        $local = Volume::factory()->local()->create();

        ['agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create(['transfer_via_server' => true]), $local);

        expect($agentJob->payload['volume']['id'])->toBe($local->id);
    });
});
