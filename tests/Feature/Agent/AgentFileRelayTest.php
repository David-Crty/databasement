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
use App\Services\Agent\Relay\RelayUploadStore;
use App\Services\Agent\Relay\RelayVolumes;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
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
 * Where a backup job may write an archive named $name: in its backup path,
 * or at the volume root when it has none.
 */
function archivePath(AgentJob $agentJob, string $name = 'archive.gz'): string
{
    $directory = trim($agentJob->payload['backup_path'], '/');

    return $directory === '' ? $name : "{$directory}/{$name}";
}

/**
 * Call a job file endpoint as an agent with a raw body. `call()` skips the
 * headers `withToken()` sets, so the token goes in as a server variable.
 */
function relayCall(string $token, string $method, string $url, string $content = ''): TestResponse
{
    return test()->call($method, $url, server: [
        'HTTP_AUTHORIZATION' => "Bearer {$token}",
        'HTTP_ACCEPT' => 'application/json',
    ], content: $content);
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
            $response->baseResponse->getStatusCode(),
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
        $path = archivePath($agentJob, 'archive.sql.gz');

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
            'path' => archivePath($agentJob, 'archive.sql.gz'),
        ]);

        relayCall($token, 'PUT', $url.'&offset=0', 'abcd')->assertOk();

        relayCall($token, 'PUT', $url.'&offset=10', 'efgh')
            ->assertConflict()
            ->assertJsonPath('received', 4);
    });

    test('completing an upload again after it was written succeeds', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);
        $base = "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volume->id}";
        $path = archivePath($agentJob);

        relayCall($token, 'PUT', "{$base}/upload?".http_build_query(['path' => $path, 'offset' => 0]), 'abcd')->assertOk();
        $complete = fn (string $contents) => $this->withToken($token)->postJson("{$base}/upload/complete", [
            'path' => $path,
            'size' => 4,
            'sha256' => hash('sha256', $contents),
        ]);

        $complete('abcd')->assertOk();
        // The agent retries when the first response is lost on the way back.
        $complete('abcd')->assertOk();
        // A different file of the same size is not taken for the committed one.
        $complete('wxyz')->assertUnprocessable();
    });

    test('a staged upload is dropped once its job ends', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);

        relayCall($token, 'PUT', "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volume->id}/upload?".http_build_query([
            'path' => archivePath($agentJob),
            'offset' => 0,
        ]), 'abcd')->assertOk();
        expect(storage_path("app/agent-relay/{$agentJob->id}"))->toBeDirectory();

        $agentJob->markFailed('Lost contact with the agent.');

        expect(storage_path("app/agent-relay/{$agentJob->id}"))->not->toBeDirectory();
    });

    test('stuck-job recovery sweeps the staged files a late write left behind', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $ended = relayBackupJob($agent, Volume::factory()->local()->create());
        $running = relayBackupJob($agent, Volume::factory()->local()->create());
        $running->claim($agent);
        $ended->markFailed('Cancelled.');
        // Written after the job ended, as by a chunk admitted just before.
        foreach (["{$ended->id}/late.part", "{$running->id}/live.part"] as $file) {
            File::ensureDirectoryExists(dirname(storage_path("app/agent-relay/{$file}")));
            File::put(storage_path("app/agent-relay/{$file}"), 'x');
            touch(dirname(storage_path("app/agent-relay/{$file}")), now()->subHour()->getTimestamp());
        }

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect(storage_path("app/agent-relay/{$ended->id}"))->not->toBeDirectory()
            ->and(storage_path("app/agent-relay/{$running->id}/live.part"))->toBeFile();
    });

    test('a backup does not overwrite a file it did not write', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);
        $path = archivePath($agentJob);
        $existing = $volume->config['path'].'/'.$path;
        File::ensureDirectoryExists(dirname($existing));
        File::put($existing, 'another snapshot');
        $base = "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volume->id}";

        relayCall($token, 'PUT', "{$base}/upload?".http_build_query(['path' => $path, 'offset' => 0]), 'evil')->assertOk();
        $this->withToken($token)
            ->postJson("{$base}/upload/complete", ['path' => $path, 'size' => 4, 'sha256' => hash('sha256', 'evil')])
            ->assertConflict();

        expect(File::get($existing))->toBe('another snapshot');
    });

    test('a backup only deletes the files it wrote', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);
        $existing = $volume->config['path'].'/'.archivePath($agentJob);
        File::ensureDirectoryExists(dirname($existing));
        File::put($existing, 'another snapshot');

        relayCall($token, 'DELETE', "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volume->id}/file?".http_build_query(['path' => archivePath($agentJob)]))
            ->assertForbidden();

        expect($existing)->toBeFile();
    });

    test('a completion sent while the first still writes is asked to wait', function () {
        $agent = Agent::factory()->create(['transfer_via_server' => true]);
        $volume = Volume::factory()->local()->create();
        $agentJob = relayBackupJob($agent, $volume);
        $token = claimAsAgent($agent, $agentJob);
        $path = archivePath($agentJob);
        app(RelayUploadStore::class)->completionLock($agentJob, $volume->id, $path)->get();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volume->id}/upload/complete", [
                'path' => $path,
                'size' => 4,
                'sha256' => hash('sha256', 'abcd'),
            ])
            ->assertAccepted();
    });

    test('the agent keeps asking while the server writes the upload, through a proxy timeout', function () {
        Sleep::fake();
        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'Gateway Timeout'], 504)
                ->push(['status' => 'in_progress'], 202)
                ->push(['status' => 'ok'], 200),
        ]);

        (new AgentApiClient('http://databasement.test', 'token'))->completeUpload('job', 'volume', 'archive.gz', 4, hash('sha256', 'abcd'));

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
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

        [$method, $volumeId, $endpoint, $path] = $request($volume, $agentJob);

        relayCall($token, $method, "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$volumeId}/{$endpoint}?".http_build_query(['path' => $path, 'offset' => 0]), 'x')
            ->assertForbidden();
    })->with([
        // Each returns [method, volume id, endpoint, path]. Typed as a Closure
        // in the test, so Pest passes it through instead of calling it.
        'writing outside the backup path' => [fn (Volume $volume) => ['PUT', $volume->id, 'upload', 'elsewhere/archive.gz']],
        'climbing out of the volume' => [fn (Volume $volume, AgentJob $agentJob) => ['PUT', $volume->id, 'upload', archivePath($agentJob, '../../etc/passwd')]],
        // Flysystem turns backslashes into slashes and resolves the `..`.
        'climbing out of the backup path with backslashes' => [fn (Volume $volume, AgentJob $agentJob) => ['PUT', $volume->id, 'upload', archivePath($agentJob, 'x\\..\\..\\other.gz')]],
        'reading as a backup' => [fn (Volume $volume, AgentJob $agentJob) => ['GET', $volume->id, 'file', archivePath($agentJob)]],
        'a volume of another job' => [fn (Volume $volume, AgentJob $agentJob) => ['PUT', Volume::factory()->local()->create()->id, 'upload', archivePath($agentJob)]],
    ]);

    test('an agent transferring directly cannot relay its other volumes', function () {
        $agent = Agent::factory()->create();
        $s3 = Volume::factory()->s3()->create();
        $agentJob = relayBackupJob($agent, $s3);
        $token = claimAsAgent($agent, $agentJob);

        relayCall($token, 'PUT', "/api/v1/agent/jobs/{$agentJob->id}/volumes/{$s3->id}/upload?".http_build_query([
            'path' => archivePath($agentJob),
            'offset' => 0,
        ]), 'x')->assertForbidden();
    });
});

describe('local volumes', function () {
    test('a restore by an agent transferring through the server reads the local copy', function () {
        $local = Volume::factory()->local()->create();

        ['agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create(['transfer_via_server' => true]), $local);

        expect($agentJob->payload['volume']['id'])->toBe($local->id);
    });
});
