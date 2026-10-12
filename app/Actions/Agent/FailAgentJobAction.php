<?php

namespace App\Actions\Agent;

use App\Models\AgentJob;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

class FailAgentJobAction
{
    use AsFake, AsObject;

    /**
     * Mark an agent job failed, keep the logs its agent reported, and let its
     * type's handler conclude whatever the job was for.
     *
     * @param  array<string, mixed>  $result  Validated failure payload (handler-specific)
     * @param  array<int, array<string, mixed>>  $logs  Log entries reported by the agent
     * @param  bool  $onlyIfPending  Leave the job alone if an agent claimed it meanwhile
     * @param  Throwable|null  $exception  What the handler records; an exception carrying the reason by default
     * @return bool False when $onlyIfPending and the job was no longer pending
     */
    public function handle(
        AgentJob $agentJob,
        string $reason,
        array $result = [],
        array $logs = [],
        bool $onlyIfPending = false,
        ?Throwable $exception = null,
    ): bool {
        if ($onlyIfPending) {
            if (! $agentJob->markFailedIfPending($reason)) {
                return false;
            }
        } else {
            $agentJob->markFailed($reason);
        }

        if ($logs !== []) {
            $agentJob->trackedJob()?->mergeLogs($logs);
        }

        $agentJob->handler()->fail($agentJob, $exception ?? new RuntimeException($reason), $result);

        return true;
    }
}
