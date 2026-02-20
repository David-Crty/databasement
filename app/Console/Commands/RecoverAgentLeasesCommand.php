<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
use Illuminate\Console\Command;
use RuntimeException;

class RecoverAgentLeasesCommand extends Command
{
    protected $signature = 'agent:recover-leases';

    protected $description = 'Recover expired agent job leases (reset or fail stale jobs)';

    public function handle(): int
    {
        $expiredJobs = AgentJob::query()
            ->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->where('lease_expires_at', '<', now())
            ->get();

        if ($expiredJobs->isEmpty()) {
            $this->info('No expired leases found.');

            return self::SUCCESS;
        }

        $resetCount = 0;
        $failedCount = 0;

        foreach ($expiredJobs as $job) {
            if ($job->attempts < $job->max_attempts) {
                // Reset to pending for retry
                $job->update([
                    'status' => AgentJob::STATUS_PENDING,
                    'agent_id' => null,
                    'lease_expires_at' => null,
                ]);
                $resetCount++;
            } else {
                // Max attempts reached — mark as failed
                $job->markFailed("Max attempts ({$job->max_attempts}) exceeded with expired lease.");
                $job->snapshot->job->markFailed(
                    new RuntimeException("Agent job failed: max attempts ({$job->max_attempts}) exceeded with expired lease.")
                );
                $failedCount++;
            }
        }

        $this->info("Recovered {$resetCount} job(s), failed {$failedCount} job(s).");

        return self::SUCCESS;
    }
}
