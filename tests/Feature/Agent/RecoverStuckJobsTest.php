<?php

use App\Facades\AppConfig;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\Snapshot;

// --- Agent job recovery (existing behavior) ---

test('recovers expired claimed agent jobs by resetting to pending', function () {
    $agent = Agent::factory()->create();
    $job = AgentJob::factory()->expiredLease($agent)->create([
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($job->agent_id)->toBeNull()
        ->and($job->lease_expires_at)->toBeNull();
});

test('fails agent jobs that exceeded max attempts', function () {
    $agent = Agent::factory()->create();
    $job = AgentJob::factory()->expiredLease($agent)->create([
        'attempts' => 3,
        'max_attempts' => 3,
    ]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($job->error_message)->toContain('Max attempts');

    // BackupJob should be failed too
    expect($job->snapshot->fresh()->job->status)->toBe('failed');
});

test('does not touch active claimed agent jobs', function () {
    $agent = Agent::factory()->create();
    $job = AgentJob::factory()->claimed($agent)->create(); // Active lease (not expired)

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_CLAIMED)
        ->and($job->agent_id)->toBe($agent->id);
});

// --- Backup job recovery (new behavior) ---

test('fails backup jobs stuck in running state beyond timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create([
        'status' => 'running',
        'started_at' => now()->subSeconds(3600 + 300 + 1), // beyond timeout + 5min grace
    ]);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('failed')
        ->and($job->error_message)->toContain('stuck in running state');
});

test('fails backup jobs stuck in pending state beyond timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create(['status' => 'pending']);
    // Manually backdate created_at beyond timeout + grace
    BackupJob::where('id', $job->id)->toBase()->update(['created_at' => now()->subSeconds(3600 + 300 + 1)]);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('failed')
        ->and($job->error_message)->toContain('stuck in pending state');
});

test('does not touch running backup jobs within timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create([
        'status' => 'running',
        'started_at' => now()->subSeconds(3600), // exactly at timeout, not beyond timeout + grace
    ]);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('running');
});

test('does not touch pending backup jobs within timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create(['status' => 'pending']);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('pending');
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

    expect($completedJob->fresh()->status)->toBe('completed')
        ->and($failedJob->fresh()->status)->toBe('failed');
});

test('outputs no stuck jobs message when nothing to recover', function () {
    $this->artisan('jobs:recover-stuck')
        ->expectsOutputToContain('No stuck jobs found')
        ->assertExitCode(0);
});
