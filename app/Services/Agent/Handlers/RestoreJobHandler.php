<?php

namespace App\Services\Agent\Handlers;

use App\Actions\Restore\CompleteRestoreAction;
use App\Actions\Restore\FailRestoreAction;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\Restore;
use App\Support\QueueTimeouts;
use RuntimeException;
use Throwable;

class RestoreJobHandler implements AgentJobHandler
{
    /**
     * A restore drops and recreates the target database, so a second run
     * after a lost agent is worse than a reported failure.
     */
    public function maxAttempts(): int
    {
        return 1;
    }

    /**
     * The restore command itself cannot heartbeat midway, and the job is
     * never retried, so the lease spans the whole job timeout, as the queued
     * restore path does.
     */
    public function leaseSeconds(): int
    {
        return max(1, QueueTimeouts::jobTimeout());
    }

    public function trackedJob(AgentJob $agentJob): ?BackupJob
    {
        return $agentJob->restore?->job;
    }

    public function completionRules(): array
    {
        return [];
    }

    public function complete(AgentJob $agentJob, array $result): array
    {
        $restore = $this->restore($agentJob);

        $agentJob->markCompleted();

        CompleteRestoreAction::run($restore);

        return [];
    }

    public function failureRules(): array
    {
        return [];
    }

    public function fail(AgentJob $agentJob, Throwable $exception, array $result): void
    {
        $restore = $this->restore($agentJob);

        FailRestoreAction::run($restore, $exception);
    }

    private function restore(AgentJob $agentJob): Restore
    {
        return $agentJob->restore ?? throw new RuntimeException("Restore agent job {$agentJob->id} has no restore.");
    }
}
