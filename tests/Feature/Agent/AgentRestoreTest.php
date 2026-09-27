<?php

use App\Enums\AgentJobType;
use App\Enums\BackupJobStatus;
use App\Facades\AppConfig;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Notifications\RestoreFailedNotification;
use App\Notifications\RestoreSuccessNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DispatchRestoreAction;
use App\Services\Backup\DTO\RestoreConfig;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Create a restore of a MySQL snapshot onto a server behind $agent and
 * dispatch it, returning the restore and the agent job it produced.
 *
 * @return array{restore: Restore, agentJob: AgentJob}
 */
function dispatchAgentRestore(Agent $agent, Volume ...$volumes): array
{
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql', 'agent_id' => $agent->id]);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)
        ->onVolumes(...($volumes ?: [Volume::factory()->s3()->create()]))
        ->create();

    $restore = app(BackupJobFactory::class)->createRestore($snapshot, $target, 'restored_db');
    app(DispatchRestoreAction::class)->execute($restore);

    return ['restore' => $restore, 'agentJob' => AgentJob::where('restore_id', $restore->id)->sole()];
}

function claimAsAgent(Agent $agent, AgentJob $agentJob): string
{
    $agentJob->claim($agent);

    return $agent->createToken('agent')->plainTextToken;
}

describe('dispatch', function () {
    test('an agent-backed target gets a single-attempt agent job instead of a queued job', function () {
        Queue::fake();

        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create());

        Queue::assertNothingPushed();
        expect($agentJob->type)->toBe(AgentJobType::Restore)
            ->and($agentJob->database_server_id)->toBe($restore->target_server_id)
            ->and($agentJob->max_attempts)->toBe(1);

        $config = RestoreConfig::fromPayload($agentJob->payload, '/tmp/work');
        expect($config->schemaName)->toBe('restored_db')
            ->and($config->snapshotFilename)->toBe($restore->snapshot->filename)
            ->and($config->snapshotDatabaseName)->toBe($restore->snapshot->database_name)
            ->and($config->targetServer->serverId)->toBe($restore->target_server_id)
            ->and($config->snapshotVolume->type)->toBe('s3');
    });

    test('the agent reads the copy it can reach, not the local one', function () {
        $s3 = Volume::factory()->s3()->create();

        ['agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create(), Volume::factory()->local()->create(), $s3);

        expect($agentJob->payload['volume']['id'])->toBe($s3->id);
    });

    test('a restore whose copy vanishes before dispatch is failed instead of left pending', function () {
        $target = DatabaseServer::factory()->create(['database_type' => 'mysql', 'agent_id' => Agent::factory()->create()->id]);
        $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
        $snapshot = Snapshot::factory()->forServer($source)->onVolumes(Volume::factory()->s3()->create())->create();
        $restore = app(BackupJobFactory::class)->createRestore($snapshot, $target, 'restored_db');

        $snapshot->files()->update(['file_exists' => false]);

        expect(fn () => app(DispatchRestoreAction::class)->execute($restore))
            ->toThrow(RuntimeException::class, 'No copy of this snapshot is available');

        expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed)
            ->and(AgentJob::where('restore_id', $restore->id)->exists())->toBeFalse();
    });

    test('an agent-backed target is rejected when the snapshot only exists on a local volume', function () {
        dispatchAgentRestore(Agent::factory()->create(), Volume::factory()->local()->create());
    })->throws(ValidationException::class, 'cannot read snapshots stored on a local volume');
});

describe('claiming', function () {
    test('agents that do not advertise restore support are not handed restore jobs', function () {
        $agent = Agent::factory()->create();
        dispatchAgentRestore($agent);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJson(['job' => null]);
    });

    test('claiming a restore job marks the restore running and leases it for the job timeout', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim', ['job_types' => ['backup', 'discover', 'restore']])
            ->assertOk()
            ->assertJsonPath('job.id', $agentJob->id)
            ->assertJsonPath('job.payload.type', 'restore');

        expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Running)
            ->and($agentJob->fresh()->lease_expires_at->timestamp)
            ->toBeGreaterThanOrEqual(now()->addSeconds(AppConfig::get('backup.job_timeout') - 5)->timestamp);
    });
});

describe('reporting', function () {
    beforeEach(function () {
        Notification::fake();
        NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);
    });

    test('acknowledging a restore job completes the restore with its logs and notifies', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        $token = claimAsAgent($agent, $agentJob);

        $restore->targetServer->update(['notification_trigger' => 'all']);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/ack", [
                'logs' => [['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'success', 'message' => 'Restore completed successfully']],
            ])
            ->assertOk();

        $restoreJob = $restore->job->fresh();
        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_COMPLETED)
            ->and($restoreJob->status)->toBe(BackupJobStatus::Completed)
            ->and(collect($restoreJob->logs)->pluck('message'))->toContain('Restore completed successfully');
        Notification::assertSentTimes(RestoreSuccessNotification::class, 1);
    });

    test('failing a restore job fails the restore and notifies', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        $token = claimAsAgent($agent, $agentJob);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/fail", ['error_message' => 'Access denied for user'])
            ->assertOk();

        $restoreJob = $restore->job->fresh();
        expect($restoreJob->status)->toBe(BackupJobStatus::Failed)
            ->and($restoreJob->error_message)->toBe('Access denied for user');
        Notification::assertSentTimes(RestoreFailedNotification::class, 1);
    });
});

describe('recovery', function () {
    test('an expired restore lease fails the restore instead of handing it out again', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        claimAsAgent($agent, $agentJob);
        $agentJob->update(['lease_expires_at' => now()->subMinute()]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed);
    });

    test('a restore no agent claimed before the timeout is never handed out afterwards', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        BackupJob::whereKey($restore->backup_job_id)->update(['created_at' => now()->subDay()]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed)
            ->and($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim', ['job_types' => ['restore']])
            ->assertJson(['job' => null]);
    });
});
