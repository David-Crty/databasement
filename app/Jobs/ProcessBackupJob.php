<?php

namespace App\Jobs;

use App\Models\Snapshot;
use App\Services\Backup\BackupTask;
use App\Services\FailureNotificationService;
use App\Support\FilesystemSupport;
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
     * Working directory for temporary files.
     */
    private string $workingDirectory;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $snapshotId
    ) {
        $this->timeout = config('backup.job_timeout');
        $this->backoff = config('backup.job_backoff');
        $this->tries = config('backup.job_tries');
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

        // Create unique working directory for this job
        $this->workingDirectory = FilesystemSupport::createWorkingDirectory('backup', $this->snapshotId);

        // Run the backup task
        $backupTask->run($snapshot, $this->workingDirectory);

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

        // Clean up working directory if it exists
        if (isset($this->workingDirectory) && is_dir($this->workingDirectory)) {
            FilesystemSupport::cleanupDirectory($this->workingDirectory);
        }

        // Mark the job as failed
        $snapshot = Snapshot::with(['job', 'databaseServer'])->findOrFail($this->snapshotId);
        if ($snapshot->job->status !== 'failed') {
            $snapshot->job->markFailed($exception);
        }

        // Send failure notification
        app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);
    }
}
