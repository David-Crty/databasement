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
use App\Notifications\RestoreFailedNotification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

/*
 * The agent lease and command heartbeat rules are specified in
 * AgentJobLivenessTest; these cover the rest of jobs:recover-stuck.
 */

describe('agent jobs', function () {
    test('fails expired discovery jobs without a snapshot', function () {
        $agent = Agent::factory()->create();
        $job = AgentJob::factory()->discover()->expiredLease($agent)->create([
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        expect($job->snapshot_id)->toBeNull();

        $this->artisan('jobs:recover-stuck')
            ->assertExitCode(0);

        expect($job->fresh()->status)->toBe(AgentJob::STATUS_FAILED);
    });

    test('a job whose failure cannot be recorded does not stop the rest of the recovery', function () {
        Exceptions::fake();
        AppConfig::set('backup.job_timeout', 3600);

        $agent = Agent::factory()->create();
        $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
        $backup = $server->backups()->first();
        // Without volumes, the failed discovery cannot be recorded as a snapshot.
        $backup->volumes()->detach();

        AgentJob::factory()->discover()->expiredLease($agent)->create([
            'database_server_id' => $server->id,
            'payload' => ['backup_id' => $backup->id],
            'attempts' => 3,
            'max_attempts' => 3,
        ]);
        $backupAgentJob = AgentJob::factory()->expiredLease($agent)->create(['attempts' => 3, 'max_attempts' => 3]);
        $stuckJob = BackupJob::create(['status' => 'pending']);
        BackupJob::where('id', $stuckJob->id)->toBase()->update(['created_at' => now()->subSeconds(3600 + 300 + 1)]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($backupAgentJob->snapshot->fresh()->job->status)->toBe(BackupJobStatus::Failed)
            ->and($stuckJob->fresh()->status)->toBe(BackupJobStatus::Failed);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'no target volumes'));
    });
});

describe('backup job timeouts', function () {
    beforeEach(function () {
        AppConfig::set('backup.job_timeout', 3600);
    });

    test('fails backup jobs stuck in running state beyond timeout, logs and notifies it', function () {
        NotificationChannel::factory()->email()->create();

        $job = BackupJob::create([
            'status' => 'running',
            'started_at' => now()->subSeconds(3600 + 300 + 1), // beyond timeout + 5min grace
        ]);
        Snapshot::factory()->create(['backup_job_id' => $job->id]);

        $this->artisan('jobs:recover-stuck')
            ->assertExitCode(0);

        $job->refresh();
        expect($job->status)->toBe(BackupJobStatus::Failed)
            ->and($job->error_message)->toContain('stuck in running state')
            ->and(collect($job->logs)->last()['message'])->toContain('stuck in running state');
        Notification::assertSentTimes(BackupFailedNotification::class, 1);
    });

    test('notifies a timed-out restore as a failed restore', function () {
        NotificationChannel::factory()->email()->create();

        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create());
        $restore->job->update(['status' => 'running', 'started_at' => now()->subSeconds(3600 + 300 + 1)]);

        $this->artisan('jobs:recover-stuck')->assertSuccessful();

        expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed)
            ->and($agentJob->fresh()->status)->toBe(AgentJob::STATUS_FAILED);
        Notification::assertSentTimes(RestoreFailedNotification::class, 1);
        Notification::assertSentTimes(BackupFailedNotification::class, 0);
    });

    test('fails backup jobs stuck in pending state beyond timeout', function () {
        $job = BackupJob::create(['status' => 'pending']);
        // Manually backdate created_at beyond timeout + grace
        BackupJob::where('id', $job->id)->toBase()->update(['created_at' => now()->subSeconds(3600 + 300 + 1)]);
        Snapshot::factory()->create(['backup_job_id' => $job->id]);

        $this->artisan('jobs:recover-stuck')
            ->assertExitCode(0);

        $job->refresh();
        expect($job->status)->toBe(BackupJobStatus::Failed)
            ->and($job->error_message)->toContain('stuck in pending state');
    });

    test('does not touch running backup jobs within timeout', function () {
        $job = BackupJob::create([
            'status' => 'running',
            'started_at' => now()->subSeconds(3600), // exactly at timeout, not beyond timeout + grace
        ]);
        Snapshot::factory()->create(['backup_job_id' => $job->id]);

        $this->artisan('jobs:recover-stuck')
            ->assertExitCode(0);

        $job->refresh();
        expect($job->status)->toBe(BackupJobStatus::Running);
    });

    test('does not touch pending backup jobs within timeout', function () {
        $job = BackupJob::create(['status' => 'pending']);
        Snapshot::factory()->create(['backup_job_id' => $job->id]);

        $this->artisan('jobs:recover-stuck')
            ->assertExitCode(0);

        $job->refresh();
        expect($job->status)->toBe(BackupJobStatus::Pending);
    });

    test('does not touch completed or failed backup jobs', function () {
        $completedJob = BackupJob::create([
            'status' => 'completed',
            'started_at' => now()->subHours(5),
            'completed_at' => now()->subHours(4),
        ]);
        Snapshot::factory()->create(['backup_job_id' => $completedJob->id]);

        $failedJob = BackupJob::create([
            'status' => 'failed',
            'started_at' => now()->subHours(5),
            'completed_at' => now()->subHours(4),
            'error_message' => 'some error',
        ]);
        Snapshot::factory()->create(['backup_job_id' => $failedJob->id]);

        $this->artisan('jobs:recover-stuck')
            ->assertExitCode(0);

        expect($completedJob->fresh()->status)->toBe(BackupJobStatus::Completed)
            ->and($failedJob->fresh()->status)->toBe(BackupJobStatus::Failed);
    });
});

test('outputs no stuck jobs message when nothing to recover', function () {
    $this->artisan('jobs:recover-stuck')
        ->expectsOutputToContain('No stuck jobs found')
        ->assertExitCode(0);
});
