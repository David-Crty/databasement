<?php

use App\Enums\SnapshotFileStatus;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\DeleteSnapshotAction;
use App\Services\Backup\SnapshotCleanupService;

/**
 * Create an agent plus a server bound to it, and the agent's API token.
 *
 * @return array{agent: Agent, server: DatabaseServer, token: string}
 */
function agentBackedServer(): array
{
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

    return [
        'agent' => $agent,
        'server' => $server,
        'token' => $agent->createToken('agent')->plainTextToken,
    ];
}

function volumePathOf(Snapshot $snapshot, string $volumeId): string
{
    $file = $snapshot->files()->with('volume')->where('volume_id', $volumeId)->firstOrFail();

    return $file->volume->config['path'].'/'.$snapshot->filename;
}

describe('delegating the deletion', function () {
    test('deleting a snapshot on an agent-backed server queues a cleanup job and keeps the record', function () {
        ['server' => $server] = agentBackedServer();
        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
        $file = $snapshot->files()->with('volume')->firstOrFail();
        $path = volumePathOf($snapshot, $file->volume_id);

        $deleted = app(DeleteSnapshotAction::class)->execute($snapshot);

        expect($deleted)->toBeFalse()
            ->and(Snapshot::find($snapshot->id))->not->toBeNull()
            ->and(file_exists($path))->toBeTrue()
            ->and($file->fresh()->status)->toBe(SnapshotFileStatus::Deleting);

        $job = AgentJob::where('snapshot_id', $snapshot->id)
            ->where('type', AgentJob::TYPE_CLEANUP)
            ->sole();

        expect($job->status)->toBe(AgentJob::STATUS_PENDING)
            ->and($job->database_server_id)->toBe($server->id)
            ->and($job->payload['type'])->toBe('cleanup')
            ->and($job->payload['targets'])->toHaveCount(1)
            ->and($job->payload['targets'][0]['volume_id'])->toBe($file->volume_id)
            ->and($job->payload['targets'][0]['filename'])->toBe($snapshot->filename)
            ->and($job->payload['targets'][0]['volume']['config']['path'])->toBe($file->volume->config['path']);
    });

    test('deleting a snapshot on a server without an agent still deletes the file synchronously', function () {
        $server = DatabaseServer::factory()->create();
        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
        $path = volumePathOf($snapshot, $snapshot->files()->firstOrFail()->volume_id);

        $deleted = app(DeleteSnapshotAction::class)->execute($snapshot);

        expect($deleted)->toBeTrue()
            ->and(Snapshot::find($snapshot->id))->toBeNull()
            ->and(file_exists($path))->toBeFalse()
            ->and(AgentJob::where('type', AgentJob::TYPE_CLEANUP)->exists())->toBeFalse();
    });

    test('keeping the files drops the record without involving the agent', function () {
        ['server' => $server] = agentBackedServer();
        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
        $path = volumePathOf($snapshot, $snapshot->files()->firstOrFail()->volume_id);

        $deleted = app(DeleteSnapshotAction::class)->execute($snapshot, keepFiles: true);

        expect($deleted)->toBeTrue()
            ->and(Snapshot::find($snapshot->id))->toBeNull()
            ->and(file_exists($path))->toBeTrue()
            ->and(AgentJob::where('type', AgentJob::TYPE_CLEANUP)->exists())->toBeFalse();
    });

    test('retention delegates expired snapshots once, without queueing duplicates', function () {
        ['server' => $server] = agentBackedServer();
        $server->backups()->firstOrFail()->update(['retention_days' => 7]);

        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
        $snapshot->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        app(SnapshotCleanupService::class)->run();
        app(SnapshotCleanupService::class)->run();

        expect(Snapshot::find($snapshot->id))->not->toBeNull()
            ->and(AgentJob::where('snapshot_id', $snapshot->id)->where('type', AgentJob::TYPE_CLEANUP)->count())->toBe(1);
    });
});

describe('the cleaned endpoint', function () {
    test('finalises the record once the agent removed every file', function () {
        ['agent' => $agent, 'server' => $server, 'token' => $token] = agentBackedServer();
        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
        $volumeId = $snapshot->files()->firstOrFail()->volume_id;
        $path = volumePathOf($snapshot, $volumeId);

        app(DeleteSnapshotAction::class)->execute($snapshot);
        $job = AgentJob::where('snapshot_id', $snapshot->id)->sole();
        $job->claim($agent);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/cleaned", [
                'targets' => [['volume_id' => $volumeId, 'status' => 'deleted']],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        // The app must not touch the volume itself: the file is still on disk
        // here precisely because only the agent was supposed to remove it.
        expect(Snapshot::find($snapshot->id))->toBeNull()
            ->and(file_exists($path))->toBeTrue();
    });

    test('keeps the record and fails the job when the agent could not delete the file', function () {
        ['agent' => $agent, 'server' => $server, 'token' => $token] = agentBackedServer();
        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
        $volumeId = $snapshot->files()->firstOrFail()->volume_id;

        app(DeleteSnapshotAction::class)->execute($snapshot);
        $job = AgentJob::where('snapshot_id', $snapshot->id)->sole();
        $job->claim($agent);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/cleaned", [
                'targets' => [['volume_id' => $volumeId, 'status' => 'failed', 'error' => 'S3 unreachable']],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', false);

        $file = $snapshot->files()->firstOrFail();

        expect(Snapshot::find($snapshot->id))->not->toBeNull()
            ->and($file->status)->toBe(SnapshotFileStatus::DeletionFailed)
            ->and($file->error)->toBe('S3 unreachable')
            ->and($job->fresh()->status)->toBe(AgentJob::STATUS_FAILED);
    });

    test('a failed copy keeps the record while the deleted copy stops being tracked', function () {
        ['agent' => $agent, 'server' => $server, 'token' => $token] = agentBackedServer();
        $first = Volume::factory()->create();
        $second = Volume::factory()->create();
        $snapshot = Snapshot::factory()->forServer($server)->onVolumes($first, $second)->create();

        app(DeleteSnapshotAction::class)->execute($snapshot);
        $job = AgentJob::where('snapshot_id', $snapshot->id)->sole();
        $job->claim($agent);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/cleaned", [
                'targets' => [
                    ['volume_id' => $first->id, 'status' => 'deleted'],
                    ['volume_id' => $second->id, 'status' => 'failed', 'error' => 'SFTP timeout'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', false);

        expect(Snapshot::find($snapshot->id))->not->toBeNull()
            ->and($snapshot->files()->where('volume_id', $first->id)->exists())->toBeFalse()
            ->and($snapshot->files()->where('volume_id', $second->id)->firstOrFail()->status)
            ->toBe(SnapshotFileStatus::DeletionFailed);
    });

    test('a copy the agent never reported on blocks the record from going away', function () {
        ['agent' => $agent, 'server' => $server, 'token' => $token] = agentBackedServer();
        $first = Volume::factory()->create();
        $second = Volume::factory()->create();
        $snapshot = Snapshot::factory()->forServer($server)->onVolumes($first, $second)->create();

        app(DeleteSnapshotAction::class)->execute($snapshot);
        $job = AgentJob::where('snapshot_id', $snapshot->id)->sole();
        $job->claim($agent);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/cleaned", [
                'targets' => [['volume_id' => $first->id, 'status' => 'deleted']],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', false);

        expect(Snapshot::find($snapshot->id))->not->toBeNull()
            ->and($job->fresh()->status)->toBe(AgentJob::STATUS_FAILED);
    });

    test('rejects a job that is not a cleanup job', function () {
        ['agent' => $agent, 'token' => $token] = agentBackedServer();
        $job = AgentJob::factory()->claimed($agent)->create();

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$job->id}/cleaned", [
                'targets' => [['volume_id' => null, 'status' => 'deleted']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This endpoint is only for cleanup jobs.');
    });

    test('rejects a job assigned to another agent', function () {
        ['server' => $server] = agentBackedServer();
        ['token' => $otherToken] = agentBackedServer();
        $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();

        app(DeleteSnapshotAction::class)->execute($snapshot);
        $job = AgentJob::where('snapshot_id', $snapshot->id)->sole();
        $job->claim($server->agent);

        $this->withToken($otherToken)
            ->postJson("/api/v1/agent/jobs/{$job->id}/cleaned", [
                'targets' => [['volume_id' => $snapshot->files()->firstOrFail()->volume_id, 'status' => 'deleted']],
            ])
            ->assertForbidden();

        expect(Snapshot::find($snapshot->id))->not->toBeNull();
    });
});
