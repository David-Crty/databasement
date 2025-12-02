<?php

namespace App\Jobs;

use App\Models\Restore;
use App\Services\Backup\RestoreTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Set to 1 (no retries) because restore operations might have already
     * partially modified the target database.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $restoreId
    ) {
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

        // Run the restore task
        $restoreTask->run($restore);

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

        // Mark the job as failed
        $restore = Restore::with('job')->find($this->restoreId);
        if ($restore->job->status !== 'failed') {
            $restore->job->markFailed($exception);
        }
    }
}
