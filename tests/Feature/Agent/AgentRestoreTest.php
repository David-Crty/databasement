<?php

use App\Enums\BackupJobStatus;
use App\Models\Agent;
use App\Models\AgentJob;

describe('claiming', function () {
    test('agents that do not advertise restore support are not handed restore jobs', function () {
        $agent = Agent::factory()->create();
        dispatchAgentRestore($agent);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim')
            ->assertOk()
            ->assertJson(['job' => null]);
    });

    test('claiming a restore job marks the restore running', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);

        $this->withToken($agent->createToken('agent')->plainTextToken)
            ->postJson('/api/v1/agent/jobs/claim', ['job_types' => ['backup', 'discover', 'restore']])
            ->assertOk()
            ->assertJsonPath('job.id', $agentJob->id)
            ->assertJsonPath('job.type', 'restore');

        expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Running);
    });
});

describe('reporting', function () {
    test('acknowledging a restore job completes the restore with its logs', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        $token = claimAsAgent($agent, $agentJob);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/ack", [
                'logs' => [['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'success', 'message' => 'Restore completed successfully']],
            ])
            ->assertOk();

        $restoreJob = $restore->job->fresh();
        expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_COMPLETED)
            ->and($restoreJob->status)->toBe(BackupJobStatus::Completed)
            ->and(collect($restoreJob->logs)->pluck('message'))->toContain('Restore completed successfully');
    });

    test('failing a restore job fails the restore with the agent\'s error', function () {
        $agent = Agent::factory()->create();
        ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore($agent);
        $token = claimAsAgent($agent, $agentJob);

        $this->withToken($token)
            ->postJson("/api/v1/agent/jobs/{$agentJob->id}/fail", ['error_message' => 'Access denied for user'])
            ->assertOk();

        $restoreJob = $restore->job->fresh();
        expect($restoreJob->status)->toBe(BackupJobStatus::Failed)
            ->and($restoreJob->error_message)->toBe('Access denied for user');
    });
});
