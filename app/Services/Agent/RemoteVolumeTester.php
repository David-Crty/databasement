<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Services\Backup\DTO\VolumeConfig;

/**
 * Runs a volume connection test on the agent that can reach the volume.
 *
 * The app cannot probe a volume on a private network, so the test is queued as
 * an agent job and its outcome is read back from that job: completed means the
 * probe succeeded, failed carries the agent's error message.
 */
readonly class RemoteVolumeTester
{
    public function __construct(private AgentJobPayloadBuilder $payloadBuilder) {}

    /**
     * Queue a test for the given config on the given agent.
     */
    public function dispatch(Agent $agent, VolumeConfig $volume): AgentJob
    {
        return AgentJob::create([
            'type' => AgentJob::TYPE_VOLUME_TEST,
            'agent_id' => $agent->id,
            'status' => AgentJob::STATUS_PENDING,
            'payload' => $this->payloadBuilder->buildVolumeTest($volume),
            // A failed probe is an answer, not a transient error worth retrying.
            'max_attempts' => 1,
        ]);
    }

    /**
     * Read the outcome of a queued test.
     *
     * @return array{state: 'pending'|'success'|'failed', message: string|null}
     */
    public function result(AgentJob $job): array
    {
        return match ($job->status) {
            AgentJob::STATUS_COMPLETED => ['state' => 'success', 'message' => null],
            AgentJob::STATUS_FAILED => ['state' => 'failed', 'message' => $job->error_message],
            default => ['state' => 'pending', 'message' => null],
        };
    }

    /**
     * True when the agent has had long enough to pick the job up and did not.
     */
    public function hasTimedOut(AgentJob $job): bool
    {
        return $job->created_at->addSeconds((int) config('agent.volume_test_timeout'))->isPast();
    }
}
