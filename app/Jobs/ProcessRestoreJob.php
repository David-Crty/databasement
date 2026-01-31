<?php

namespace App\Jobs;

use App\Models\Restore;
use App\Services\Backup\RestoreTask;
use App\Services\FailureNotificationService;
use App\Support\FilesystemSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRestoreJob implements ShouldQueue
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
        public string $restoreId
    ) {
        $this->timeout = config('backup.job_timeout');
        $this->backoff = config('backup.job_backoff');
        $this->tries = config('backup.job_tries');
        $this->onQueue('backups');
    }

    /**
     * Execute the job.
     */
    public function handle(RestoreTask $restoreTask): void
    {
        $restore = Restore::with(['job', 'snapshot.volume', 'snapshot.databaseServer', 'targetServer'])
            ->findOrFail($this->restoreId);

        // Update job with queue job ID for tracking
        $restore->job->update(['job_id' => $this->job->getJobId()]);

        // Create unique working directory for this job
        $this->workingDirectory = FilesystemSupport::createWorkingDirectory('restore', $this->restoreId);

        // Run the restore task
        $restoreTask->run($restore, $this->workingDirectory);

        Log::info('Restore completed successfully', [
            'restore_id' => $this->restoreId,
            'snapshot_id' => $restore->snapshot_id,
            'target_server_id' => $restore->target_server_id,
            'schema_name' => $restore->schema_name,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Restore job failed', [
            'restore_id' => $this->restoreId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Clean up working directory if it exists
        if (isset($this->workingDirectory) && is_dir($this->workingDirectory)) {
            FilesystemSupport::cleanupDirectory($this->workingDirectory);
        }

        // Mark the job as failed
        $restore = Restore::with(['job', 'targetServer', 'snapshot'])->findOrFail($this->restoreId);
        if ($restore->job->status !== 'failed') {
            $restore->job->markFailed($exception);
        }

        // Send failure notification
        app(FailureNotificationService::class)->notifyRestoreFailed($restore, $exception);
    }
}
