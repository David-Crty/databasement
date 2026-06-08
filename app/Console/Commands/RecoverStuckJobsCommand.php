<?php

namespace App\Console\Commands;

use App\Facades\AppConfig;
use App\Models\BackupJob;
use Illuminate\Console\Command;
use RuntimeException;

class RecoverStuckJobsCommand extends Command
{
    /** Grace period added to the configured timeout before a job is considered stuck. */
    private const GRACE_PERIOD_SECONDS = 300;

    protected $signature = 'jobs:recover-stuck';

    protected $description = 'Recover backup jobs stuck in running/pending state beyond their timeout';

    public function handle(): int
    {
        $timeout = AppConfig::get('backup.job_timeout') + self::GRACE_PERIOD_SECONDS;
        $cutoff = now()->subSeconds($timeout);

        $stuckJobs = BackupJob::query()
            ->whereIn('status', ['running', 'pending'])
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    $q->where('status', 'running')
                        ->where('started_at', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->where('status', 'pending')
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->get();

        if ($stuckJobs->isEmpty()) {
            $this->info('No stuck jobs found.');

            return self::SUCCESS;
        }

        foreach ($stuckJobs as $job) {
            $job->markFailed(
                new RuntimeException('Job timed out: stuck in '.$job->status.' state beyond the configured timeout.')
            );
        }

        $this->info("Backup jobs: failed {$stuckJobs->count()} stuck job(s).");

        return self::SUCCESS;
    }
}
