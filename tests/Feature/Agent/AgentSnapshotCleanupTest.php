<?php

use App\Actions\Snapshot\DeleteSnapshotAction;
use App\Actions\Snapshot\FinishSnapshotDeletionAction;
use App\Enums\VolumeType;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\Runners\CleanupJobRunner;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\SnapshotCleanupService;
use App\Support\QueueTimeouts;
use League\Flysystem\Filesystem;

/**
 * A completed snapshot of a server backed by $agent, stored on $volumes (one
 * S3 volume by default).
 */
function agentBackedSnapshot(Agent $agent, Volume ...$volumes): Snapshot
{
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

    return Snapshot::factory()->forServer($server)
        ->onVolumes(...($volumes ?: [Volume::factory()->s3()->create()]))
        ->withFile()
        ->create();
}

/**
 * Queue the deletion of an agent-backed snapshot and claim the cleanup job as
 * its agent.
 *
 * @return array{snapshot: Snapshot, agentJob: AgentJob, token: string}
 */
function claimedSnapshotCleanup(Volume ...$volumes): array
{
    $agent = Agent::factory()->create();
    $snapshot = agentBackedSnapshot($agent, ...$volumes);
    DeleteSnapshotAction::run($snapshot);

    $agentJob = AgentJob::where('snapshot_id', $snapshot->id)->sole();

    return ['snapshot' => $snapshot, 'agentJob' => $agentJob, 'token' => claimAsAgent($agent, $agentJob)];
}

/**
 * Let the app reach only its local volumes, as when the others sit on the
 * agent's private network.
 */
function appReachesOnlyLocalVolumes(): void
{
    $realProvider = app(FilesystemProvider::class);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturnUsing(
        fn (Volume $volume) => $volume->type === VolumeType::LOCAL->value
            ? $realProvider->getForVolume($volume)
            : throw new RuntimeException('Volume unreachable'),
    );
    app()->instance(FilesystemProvider::class, $provider);
}

beforeEach(fn () => appReachesOnlyLocalVolumes());

describe('dispatch', function () {
    test('retention hands an expired snapshot to its agent once, not again while the agent has not reported', function () {
        $snapshot = agentBackedSnapshot(Agent::factory()->create());
        $snapshot->backup->update(['retention_days' => 7]);
        $snapshot->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        app(SnapshotCleanupService::class)->run();
        app(SnapshotCleanupService::class)->run();

        expect(AgentJob::where('snapshot_id', $snapshot->id)->count())->toBe(1);
    });
});

describe('claiming', function () {
    test('agents that do not advertise cleanup support are not handed cleanup jobs', function () {
        $agent = Agent::factory()->create();
        DeleteSnapshotAction::run(agentBackedSnapshot($agent));

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim', ['job_types' => ['backup', 'discover', 'restore']])
            ->assertOk()
            ->assertJson(['job' => null]);
    });
});

describe('reporting', function () {
    test('the agent removing every file deletes the snapshot', function () {
        ['snapshot' => $snapshot, 'agentJob' => $agentJob, 'token' => $token] = claimedSnapshotCleanup();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/ack", [
                'files' => [['id' => $snapshot->files()->sole()->id, 'error' => null]],
            ])
            ->assertOk();

        expect(Snapshot::find($snapshot->id))->toBeNull();
    });

    test('a failure report passes each file\'s outcome to the deletion', function () {
        $removed = Volume::factory()->s3()->create();
        $failed = Volume::factory()->s3()->create();
        ['snapshot' => $snapshot, 'agentJob' => $agentJob, 'token' => $token] = claimedSnapshotCleanup($removed, $failed);
        $files = $snapshot->files()->get()->keyBy('volume_id');

        FinishSnapshotDeletionAction::shouldRun()
            ->once()
            ->withArgs(fn (Snapshot $target, array $outcomes) => $target->is($snapshot) && $outcomes === [
                $files[$removed->id]->id => null,
                $files[$failed->id]->id => 'Access Denied',
            ]);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/fail", [
                'error_message' => 'Could not delete the file from every volume.',
                'files' => [
                    ['id' => $files[$removed->id]->id, 'error' => null],
                    ['id' => $files[$failed->id]->id, 'error' => 'Access Denied'],
                ],
            ])
            ->assertOk();
    });

    test('a cleanup job no agent picks up is failed, so its snapshot leaves the deleting state', function () {
        $snapshot = agentBackedSnapshot(Agent::factory()->create());
        DeleteSnapshotAction::run($snapshot);

        $this->travel(QueueTimeouts::retryAfter() + 1)->seconds();
        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect(AgentJob::where('snapshot_id', $snapshot->id)->sole()->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($snapshot->fresh()->deleting)->toBeFalse()
            ->and($snapshot->files()->sole()->error)->toContain('No agent picked up');
    });
});

describe('agent', function () {
    test('the agent still reports the files it removed when acknowledging the job fails', function () {
        ['snapshot' => $snapshot, 'agentJob' => $agentJob] = claimedSnapshotCleanup();

        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('fileExists')->andReturnFalse();
        $provider = Mockery::mock(FilesystemProvider::class);
        $provider->shouldReceive('getForVolumeConfig')->andReturn($filesystem);

        $client = Mockery::mock(AgentApiClient::class);
        $client->shouldReceive('ack')->andThrow(new RuntimeException('Server unreachable'));
        $client->shouldReceive('fail')
            ->once()
            ->with($agentJob->id, 'Server unreachable', [], ['files' => [['id' => $snapshot->files()->sole()->id, 'error' => null]]]);

        (new CleanupJobRunner($provider))->run(['id' => $agentJob->id, 'payload' => $agentJob->payload], $client, fn () => null);
    });
});
