<?php

namespace App\Jobs;

use App\Models\Snapshot;
use App\Services\Backup\BackupTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $snapshotId
    ) {
        $this->onQueue('backups');
    }

    /**
     * Execute the job.
     */
    public function handle(BackupTask $backupTask): void
    {
        $snapshot = Snapshot::with(['job', 'volume', 'databaseServer'])->findOrFail($this->snapshotId);

        // Update job with queue job ID for tracking
        $snapshot->job->update(['job_id' => $this->job->getJobId()]);

        // Run the backup task
        $backupTask->run($snapshot);

        Log::info('Backup completed successfully', [
            'snapshot_id' => $this->snapshotId,
            'database_server_id' => $snapshot->databaseServer->id,
            'method' => $snapshot->method,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Backup job failed', [
            'snapshot_id' => $this->snapshotId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark the job as failed
        $snapshot = Snapshot::with('job')->find($this->snapshotId);
        if ($snapshot->job->status !== 'failed') {
            $snapshot->job->markFailed($exception);
        }
    }
}
