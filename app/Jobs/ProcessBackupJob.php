<?php

namespace App\Jobs;

use App\Facades\AppConfig;
use App\Models\Snapshot;
use App\Services\Backup\BackupTask;
use App\Services\FailureNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public int $backoff;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $snapshotId
    ) {
        $this->timeout = AppConfig::get('backup.job_timeout');
        $this->backoff = AppConfig::get('backup.job_backoff');
        $this->tries = AppConfig::get('backup.job_tries');
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
        $backupTask->run($snapshot, $this->attempts(), $this->tries);

        Log::info('Backup completed successfully', [
            'snapshot_id' => $this->snapshotId,
            'database_server_id' => $snapshot->databaseServer->id,
            'method' => $snapshot->method,
        ]);
    }

    /**
     * Handle a job failure (called by Laravel queue after all retries exhausted).
     * Note: Job is already marked as failed by BackupTask::run() catch block.
     */
    public function failed(\Throwable $exception): void
    {
        $snapshot = Snapshot::with(['databaseServer'])->findOrFail($this->snapshotId);
        app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);
    }
}
