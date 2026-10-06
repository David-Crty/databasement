<?php

namespace App\Services\Agent\Handlers;

use App\Models\AgentJob;
use App\Models\BackupJob;
use Throwable;

/**
 * App-side lifecycle of one agent job type: how long a claim holds, how often
 * it may be retried, and what the agent's report does to the app's records.
 * The agent side counterpart is an AgentJobRunner.
 */
interface AgentJobHandler
{
    public function maxAttempts(): int;

    /**
     * Seconds a claim or heartbeat keeps the job leased.
     */
    public function leaseSeconds(): int;

    /**
     * The job record the UI shows for this agent job, which receives its
     * status and logs; null when the job type has none.
     */
    public function trackedJob(AgentJob $agentJob): ?BackupJob;

    /**
     * Validation rules for the result an agent sends when it completes the job.
     *
     * @return array<string, mixed>
     */
    public function completionRules(): array;

    /**
     * Record a completed job.
     *
     * @param  array<string, mixed>  $result  The validated completion result
     * @return array<string, mixed> Extra fields for the agent's response
     */
    public function complete(AgentJob $agentJob, array $result): array;

    /**
     * Validation rules for the partial result an agent may send alongside a failure.
     *
     * @return array<string, mixed>
     */
    public function failureRules(): array;

    /**
     * Record a failed job. The agent job itself is already marked failed.
     *
     * @param  array<string, mixed>  $result  The validated failure result
     */
    public function fail(AgentJob $agentJob, Throwable $exception, array $result): void;
}
