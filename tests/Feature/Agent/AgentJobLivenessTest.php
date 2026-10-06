<?php

use App\Enums\BackupJobStatus;
use App\Facades\AppConfig;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * How the server tells an agent job that is still running from one whose
 * agent was lost:
 *
 * - Lease: claiming a job leases it to the agent and every heartbeat extends
 *   the lease. Backups and restores are leased for the whole job timeout,
 *   discoveries for five minutes.
 * - Command heartbeat: a runner reports a running command's log entry every
 *   30 seconds (the queue worker to the database, an agent in a heartbeat),
 *   and every log write made while a command runs refreshes the job's
 *   `command_heartbeat_at`. Agents up to 1.8.4 never report a running command.
 * - Recovery (`jobs:recover-stuck`): a job whose lease expired, or a backup
 *   whose command heartbeat went stale, is retried while attempts remain and
 *   failed otherwise. Restores are only recovered by lease and never retried.
 */

beforeEach(function () {
    AppConfig::set('backup.job_timeout', 3600);
});

function claimNextJob(Agent $agent): TestResponse
{
    return test()->withToken($agent->createToken('agent')->plainTextToken)
        ->postJson('/api/v1/agent/jobs/claim', ['job_types' => ['backup', 'discover', 'restore']])
        ->assertOk();
}

function pendingBackupFor(Agent $agent): AgentJob
{
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);

    return AgentJob::factory()->create(['snapshot_id' => Snapshot::factory()->forServer($server)->create()->id]);
}

function leaseSecondsLeft(AgentJob $agentJob): int
{
    return (int) now()->diffInSeconds($agentJob->fresh()->lease_expires_at);
}

describe('lease', function () {
    test('a claim leases a backup or a restore for the whole job timeout', function (string $type) {
        $agent = Agent::factory()->create();
        $agentJob = $type === 'restore' ? dispatchAgentRestore($agent)['agentJob'] : pendingBackupFor($agent);

        claimNextJob($agent)->assertJsonPath('job.id', $agentJob->id);

        expect(leaseSecondsLeft($agentJob))->toBeBetween(3595, 3600);
    })->with(['backup', 'restore']);

    test('a claim leases a discovery for five minutes', function () {
        $agent = Agent::factory()->create();
        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
        $agentJob = AgentJob::factory()->discover()->create(['database_server_id' => $server->id]);

        claimNextJob($agent)->assertJsonPath('job.id', $agentJob->id);

        expect(leaseSecondsLeft($agentJob))->toBeBetween(295, 300);
    });

    test('a heartbeat extends the lease for the whole job timeout', function () {
        $agent = Agent::factory()->create();
        $agentJob = AgentJob::factory()->claimed($agent)->create(['lease_expires_at' => now()->addMinute()]);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat")
            ->assertOk();

        expect(leaseSecondsLeft($agentJob))->toBeBetween(3595, 3600);
    });
});

describe('command heartbeat', function () {
    test('a running command keeps its job heartbeat fresh until it finishes, on the queue', function () {
        $backupJob = BackupJob::create(['status' => 'running']);

        $index = $backupJob->startCommandLog('pg_dump app');
        expect($backupJob->fresh()->command_heartbeat_at)->not->toBeNull();

        $backupJob->updateCommandLog($index, ['status' => 'completed', 'exit_code' => 0]);
        expect($backupJob->fresh()->command_heartbeat_at)->toBeNull();
    });

    test('a running command keeps its job heartbeat fresh until it finishes, through an agent', function () {
        $agent = Agent::factory()->create();
        $agentJob = AgentJob::factory()->claimed($agent)->create();
        $backupJob = $agentJob->trackedJob();
        $heartbeat = fn (string $status) => $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/heartbeat", ['logs' => [
                ['timestamp' => now()->toIso8601String(), 'type' => 'command', 'command' => 'pg_dump app', 'status' => $status],
            ]])
            ->assertOk();

        $heartbeat('running');
        expect($backupJob->fresh()->command_heartbeat_at)->not->toBeNull();

        $heartbeat('completed');
        expect($backupJob->fresh()->command_heartbeat_at)->toBeNull();
    });

    test('a claim fails the command a previous attempt left running', function () {
        $agent = Agent::factory()->create();
        $agentJob = pendingBackupFor($agent);
        $agentJob->trackedJob()->startCommandLog('pg_dump app');

        claimNextJob($agent)->assertJsonPath('job.id', $agentJob->id);

        $backupJob = $agentJob->trackedJob()->fresh();
        expect($backupJob->command_heartbeat_at)->toBeNull()
            ->and($backupJob->logs[0]['status'])->toBe('failed');
    });
});

describe('recovery', function () {
    test('a job whose lease expired is retried while attempts remain', function () {
        $agentJob = AgentJob::factory()->expiredLease()->create(['attempts' => 1, 'max_attempts' => 3]);
        $agentJob->trackedJob()->startCommandLog('pg_dump app');

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        $agentJob->refresh();
        $logs = $agentJob->trackedJob()->logs;
        expect($agentJob->status)->toBe(AgentJob::STATUS_PENDING)
            ->and($agentJob->agent_id)->toBeNull()
            ->and($agentJob->lease_expires_at)->toBeNull()
            ->and($logs[0]['status'])->toBe('failed')
            ->and($logs[1]['message'])->toBe('Lost contact with the agent, the job will be retried.');
    });

    test('a job whose lease expired is failed and notified once attempts run out', function () {
        Notification::fake();
        NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);
        $agentJob = AgentJob::factory()->expiredLease()->create(['attempts' => 3, 'max_attempts' => 3]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($agentJob->fresh()->error_message)->toContain('after losing contact with the agent')
            ->and($agentJob->trackedJob()->status)->toBe(BackupJobStatus::Failed);
        Notification::assertSentTimes(BackupFailedNotification::class, 1);
    });

    test('a job whose lease is still valid is left alone', function () {
        $agent = Agent::factory()->create();
        $agentJob = AgentJob::factory()->claimed($agent)->create();

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_CLAIMED)
            ->and($agentJob->fresh()->agent_id)->toBe($agent->id);
    });

    test('a backup whose command heartbeat went stale is retried before its lease expires', function () {
        $agentJob = AgentJob::factory()->claimed()->create(['lease_expires_at' => now()->addHour(), 'max_attempts' => 3]);
        $agentJob->trackedJob()->update(['command_heartbeat_at' => now()->subSeconds(601)]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_PENDING)
            ->and($agentJob->fresh()->agent_id)->toBeNull();
    });

    test('a backup is left alone while its command heartbeat is fresh or was never sent', function (?int $secondsAgo) {
        $agentJob = AgentJob::factory()->claimed()->create(['lease_expires_at' => now()->addHour()]);
        $agentJob->trackedJob()->update([
            'command_heartbeat_at' => $secondsAgo === null ? null : now()->subSeconds($secondsAgo),
        ]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_CLAIMED);
    })->with([
        'fresh heartbeat' => [30],
        'agent up to 1.8.4' => [null],
    ]);

    test('a restore whose lease expired is failed rather than run again', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        claimAsAgent($agent, $agentJob);
        $agentJob->update(['lease_expires_at' => now()->subMinute()]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED)
            ->and($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed);
    });

    test('a restore is left to its lease even when its command heartbeat went stale', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        claimAsAgent($agent, $agentJob);
        $restore->job->update(['command_heartbeat_at' => now()->subHour()]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_CLAIMED)
            ->and($restore->job->fresh()->status)->not->toBe(BackupJobStatus::Failed);
    });

    test('a backup that timed out cannot be completed by its agent afterwards', function () {
        $backupJob = BackupJob::create(['status' => 'running', 'started_at' => now()->subSeconds(3600 + 300 + 1)]);
        $agent = Agent::factory()->create();
        $agentJob = AgentJob::factory()->claimed($agent)->create([
            'snapshot_id' => Snapshot::factory()->create(['backup_job_id' => $backupJob->id])->id,
        ]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/ack", ['filename' => 'backup.sql.gz', 'file_size' => 1])
            ->assertConflict();

        expect($backupJob->fresh()->status)->toBe(BackupJobStatus::Failed);
    });

    test('a restore no agent claimed before the timeout is never handed out afterwards', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        BackupJob::whereKey($restore->backup_job_id)->update(['created_at' => now()->subDay()]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed)
            ->and($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED);

        claimNextJob($agent)->assertJson(['job' => null]);
    });
});
