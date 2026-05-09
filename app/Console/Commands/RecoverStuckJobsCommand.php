<?php

namespace App\Console\Commands;

use App\Facades\AppConfig;
use App\Models\AgentJob;
use App\Models\BackupJob;
use Illuminate\Console\Command;
use RuntimeException;

class RecoverStuckJobsCommand extends Command
{
    protected $signature = 'jobs:recover-stuck';

    protected $description = 'Recover stuck jobs (expired agent leases and timed-out backup jobs)';

    public function handle(): int
    {
        $agentResult = $this->recoverAgentJobs();
        $backupResult = $this->recoverBackupJobs();

        if (! $agentResult && ! $backupResult) {
            $this->info('No stuck jobs found.');
        }

        return self::SUCCESS;
    }

    /**
     * Recover expired agent job leases (reset or fail stale jobs).
     */
    private function recoverAgentJobs(): bool
    {
        $expiredJobs = AgentJob::query()
            ->with(['snapshot.job'])
            ->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->where('lease_expires_at', '<', now())
            ->get();

        if ($expiredJobs->isEmpty()) {
            return false;
        }

        $resetCount = 0;
        $failedCount = 0;

        foreach ($expiredJobs as $job) {
            if ($job->attempts < $job->max_attempts) {
                $job->update([
                    'status' => AgentJob::STATUS_PENDING,
                    'agent_id' => null,
                    'lease_expires_at' => null,
                ]);
                $resetCount++;
            } else {
                $errorMessage = "Max attempts ({$job->max_attempts}) exceeded with expired lease.";
                $job->markFailed($errorMessage);

                $job->snapshot->job->markFailed(
                    new RuntimeException("Agent job failed: {$errorMessage}")
                );
                $failedCount++;
            }
        }

        $this->info("Agent jobs: recovered {$resetCount}, failed {$failedCount}.");

        return true;
    }

    /**
     * Recover backup jobs stuck in running/pending state beyond their timeout.
     */
    private function recoverBackupJobs(): bool
    {
        $gracePeriod = 300; // 5 minutes grace period
        $timeout = AppConfig::get('backup.job_timeout') + $gracePeriod;
        $cutoff = now()->subSeconds($timeout);

        $stuckJobs = BackupJob::query()
            ->whereIn('status', ['running', 'pending'])
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    // Running jobs: check started_at
                    $q->where('status', 'running')
                        ->where('started_at', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    // Pending jobs: check created_at
                    $q->where('status', 'pending')
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->get();

        if ($stuckJobs->isEmpty()) {
            return false;
        }

        foreach ($stuckJobs as $job) {
            $job->markFailed(
                new RuntimeException('Job timed out: stuck in '.$job->status.' state beyond the configured timeout.')
            );
        }

        $this->info("Backup jobs: failed {$stuckJobs->count()} stuck job(s).");

        return true;
    }
}
