<?php

use App\Models\Agent;
use App\Models\AgentJob;

test('recovers expired claimed jobs by resetting to pending', function () {
    $agent = Agent::factory()->create();
    $job = AgentJob::factory()->expiredLease($agent)->create([
        'attempts' => 1,
        'max_attempts' => 3,
    ]);

    $this->artisan('agent:recover-leases')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($job->agent_id)->toBeNull()
        ->and($job->lease_expires_at)->toBeNull();
});

test('fails jobs that exceeded max attempts', function () {
    $agent = Agent::factory()->create();
    $job = AgentJob::factory()->expiredLease($agent)->create([
        'attempts' => 3,
        'max_attempts' => 3,
    ]);

    $this->artisan('agent:recover-leases')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($job->error_message)->toContain('Max attempts');

    // BackupJob should be failed too
    expect($job->snapshot->fresh()->job->status)->toBe('failed');
});

test('does nothing when no expired leases exist', function () {
    $this->artisan('agent:recover-leases')
        ->expectsOutputToContain('No expired leases found')
        ->assertExitCode(0);
});

test('does not touch active claimed jobs', function () {
    $agent = Agent::factory()->create();
    $job = AgentJob::factory()->claimed($agent)->create(); // Active lease (not expired)

    $this->artisan('agent:recover-leases')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_CLAIMED)
        ->and($job->agent_id)->toBe($agent->id);
});
