<?php

use App\Enums\BackupJobStatus;
use App\Exceptions\Backup\JobCancelledException;
use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\RestoreTask;
use Illuminate\Support\Facades\Notification;

/*
 * Cancelling a backup or restore in progress:
 *
 * - The job is marked cancelled at once, and a command left running in its
 *   logs is marked failed.
 * - Its runner stops at its next write: a log line between two steps, or the
 *   report of a running command every 30 seconds. The queue worker finds the
 *   job cancelled when it writes it; an agent's job heartbeat is refused.
 * - A cancelled job is never retried, nor reported as failed.
 */

function runningBackupJob(): BackupJob
{
    $server = DatabaseServer::factory()->create(['database_names' => ['app']]);
    $job = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0]->job;
    $job->markRunning();

    return $job;
}

/**
 * The queue job for a pending backup or restore, and the task it runs.
 *
 * @return array{0: BackupJob, 1: Closure(BackupTask|RestoreTask): void, 2: class-string}
 */
function queuedJob(string $type): array
{
    if ($type === 'backup') {
        $server = DatabaseServer::factory()->create(['database_names' => ['app']]);
        $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

        return [$snapshot->job, fn ($task) => (new ProcessBackupJob($snapshot->id))->handle($task), BackupTask::class];
    }

    $snapshot = Snapshot::factory()->withFile()->create();
    $restore = app(BackupJobFactory::class)->createRestore(
        $snapshot,
        DatabaseServer::factory()->create(['database_type' => $snapshot->database_type]),
        'restored',
    );

    return [$restore->job, fn ($task) => (new ProcessRestoreJob($restore->id))->handle($task), RestoreTask::class];
}

describe('cancelling', function () {
    test('a job in progress is cancelled, and its running command marked failed', function () {
        $job = runningBackupJob();
        $job->startCommandLog('pg_dump app');

        expect($job->cancel('Alice'))->toBeTrue();

        $job->refresh();
        expect($job->status)->toBe(BackupJobStatus::Cancelled)
            ->and($job->error_message)->toBe('Cancelled by Alice.')
            ->and($job->command_heartbeat_at)->toBeNull()
            ->and($job->logs[0]['status'])->toBe('failed')
            ->and($job->logs[1]['message'])->toBe('Cancelled by Alice.');
    });

    test('a finished job is left as it is', function () {
        $job = Snapshot::factory()->create()->job;

        expect($job->cancel('Alice'))->toBeFalse()
            ->and($job->fresh()->status)->toBe(BackupJobStatus::Completed);
    });
});

describe('on the queue', function () {
    test('the runner is stopped at its next write, and its remaining writes are dropped', function () {
        $job = runningBackupJob();
        BackupJob::find($job->id)->cancel('Alice');

        expect(fn () => $job->log('Dump done'))->toThrow(JobCancelledException::class);

        $job->log('Cleaning up temporary files');
        $job->markFailed(new RuntimeException('Dump failed'));

        $job->refresh();
        expect($job->status)->toBe(BackupJobStatus::Cancelled)
            ->and(collect($job->logs)->pluck('message')->all())->toBe(['Cancelled by Alice.']);
    });

    test('a job cancelled while it runs ends without a retry or a failure notification', function (string $type) {
        Notification::fake();
        [$job, $handle, $taskClass] = queuedJob($type);

        $task = Mockery::mock($taskClass);
        $task->shouldReceive('execute')->once()->andReturnUsing(function ($config, BackupJob $logger) use ($job) {
            BackupJob::find($job->id)->cancel('Alice');
            $logger->log('Next step');
        });

        $handle($task);

        expect($job->fresh()->status)->toBe(BackupJobStatus::Cancelled);
        Notification::assertNothingSent();
    })->with(['backup', 'restore']);

    test('a job cancelled before a worker picked it up never runs', function (string $type) {
        [$job, $handle, $taskClass] = queuedJob($type);
        $job->cancel('Alice');

        $task = Mockery::mock($taskClass);
        $task->shouldNotReceive('execute');

        $handle($task);

        expect($job->fresh()->status)->toBe(BackupJobStatus::Cancelled);
    })->with(['backup', 'restore']);
});

describe('through an agent', function () {
    test('the agent running a cancelled job is refused at its next report', function (string $type) {
        $agent = Agent::factory()->create();
        $agentJob = $type === 'restore'
            ? dispatchAgentRestore($agent)['agentJob']
            : AgentJob::factory()->create(['snapshot_id' => Snapshot::factory()->forServer(DatabaseServer::factory()->create(['agent_id' => $agent->id]))->create()->id]);
        $token = claimAsAgent($agent, $agentJob);
        $job = $agentJob->trackedJob();
        $job->markRunning();

        $job->cancel('Alice');

        $this->withToken($token)->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat", ['logs' => []])->assertConflict();
        $this->withToken($token)->postJson("/api/v1/agent/jobs/{$agentJob->id}/ack", [])->assertConflict();
        expect($job->fresh()->status)->toBe(BackupJobStatus::Cancelled);
    })->with(['backup', 'restore']);

    test('a job cancelled before an agent claimed it is never handed out', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore] = dispatchAgentRestore($agent);

        $restore->job->cancel('Alice');

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim', ['job_types' => ['backup', 'restore']])
            ->assertJsonPath('job', null);
    });
});
