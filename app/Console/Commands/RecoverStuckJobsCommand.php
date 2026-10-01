<?php

namespace App\Console\Commands;

use App\Enums\AgentJobType;
use App\Enums\BackupJobStatus;
use App\Facades\AppConfig;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Support\QueueTimeouts;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

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
            ->with(['snapshot.job', 'restore.job'])
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

                try {
                    $job->handler()->fail($job, new RuntimeException("Agent job failed: {$errorMessage}"), []);
                } catch (Throwable $e) {
                    report($e);
                }
                $failedCount++;
            }
        }

        $this->info("Agent jobs: recovered {$resetCount}, failed {$failedCount}.");

        return true;
    }

    /**
     * Recover backup jobs stuck in running/pending state beyond their timeout.
     *
     * Running jobs are compared against started_at, while pending jobs (which
     * were never picked up) are compared against created_at. A grace period is
     * added on top of the configured timeout to avoid killing jobs that are
     * still legitimately processing.
     */
    private function recoverBackupJobs(): bool
    {
        $timeout = AppConfig::get('backup.job_timeout') + QueueTimeouts::RETRY_GRACE_SECONDS;
        $cutoff = now()->subSeconds($timeout);

        $stuckJobs = BackupJob::query()
            ->inProgress()
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    $q->where('status', BackupJobStatus::Running)
                        ->where('started_at', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->where('status', BackupJobStatus::Pending)
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->get();

        if ($stuckJobs->isEmpty()) {
            return false;
        }

        foreach ($stuckJobs as $job) {
            $job->markFailed(
                new RuntimeException('Job timed out: stuck in '.$job->status->value.' state beyond the configured timeout.')
            );
        }

        // A restore an agent never picked up must not run once it is reported failed.
        AgentJob::query()
            ->where('type', AgentJobType::Restore)
            ->where('status', AgentJob::STATUS_PENDING)
            ->whereHas('restore', fn ($query) => $query->whereIn('backup_job_id', $stuckJobs->modelKeys()))
            ->get()
            ->each(fn (AgentJob $agentJob) => $agentJob->markFailed('Restore timed out before an agent claimed it.'));

        // A timed-out backup must not be revived by an agent claiming or finishing it later.
        AgentJob::query()
            ->where('type', AgentJobType::Backup)
            ->whereIn('status', [AgentJob::STATUS_PENDING, AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->whereHas('snapshot', fn ($query) => $query->whereIn('backup_job_id', $stuckJobs->modelKeys()))
            ->get()
            ->each(fn (AgentJob $agentJob) => $agentJob->markFailed('Backup timed out.'));

        $this->info("Backup jobs: failed {$stuckJobs->count()} stuck job(s).");

        return true;
    }
}
