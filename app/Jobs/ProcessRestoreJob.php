<?php

namespace App\Jobs;

use App\Facades\AppConfig;
use App\Models\Restore;
use App\Services\Backup\RestoreTask;
use App\Services\FailureNotificationService;
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
     * Create a new job instance.
     */
    public function __construct(
        public string $restoreId
    ) {
        $this->timeout = AppConfig::get('backup.job_timeout');
        $this->backoff = AppConfig::get('backup.job_backoff');
        $this->tries = AppConfig::get('backup.job_tries');
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
        $restoreTask->run($restore, $this->attempts(), $this->tries);

        Log::info('Restore completed successfully', [
            'restore_id' => $this->restoreId,
            'snapshot_id' => $restore->snapshot_id,
            'target_server_id' => $restore->target_server_id,
            'schema_name' => $restore->schema_name,
        ]);
    }

    /**
     * Handle a job failure (called by Laravel queue after all retries exhausted).
     * Note: Job is already marked as failed by RestoreTask::run() catch block.
     */
    public function failed(\Throwable $exception): void
    {
        $restore = Restore::with(['targetServer', 'snapshot'])->findOrFail($this->restoreId);
        app(FailureNotificationService::class)->notifyRestoreFailed($restore, $exception);
    }
}
