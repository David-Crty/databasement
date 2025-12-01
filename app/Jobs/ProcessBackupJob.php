<?php

namespace App\Jobs;

use App\Models\DatabaseServer;
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
        public string $databaseServerId,
        public string $method = 'manual',
        public ?string $userId = null,
        public ?string $snapshotId = null
    ) {
        $this->onQueue('backups');
    }

    /**
     * Execute the job.
     */
    public function handle(BackupTask $backupTask): void
    {
        // Fetch the database server with relationships
        $databaseServer = DatabaseServer::with(['backup.volume'])->findOrFail($this->databaseServerId);

        if (! $databaseServer->backup) {
            throw new \RuntimeException('No backup configuration found for this database server.');
        }

        // If snapshot was pre-created, fetch it and update its job with the queue job ID
        if ($this->snapshotId) {
            $snapshot = Snapshot::with('job')->findOrFail($this->snapshotId);
            $snapshot->job->update(['job_id' => $this->job->getJobId()]);
        }

        // Run the backup task (it will create snapshot and job, handling status updates)
        $backupTask->run(
            databaseServer: $databaseServer,
            method: $this->method,
            userId: $this->userId
        );

        Log::info('Backup completed successfully', [
            'database_server_id' => $this->databaseServerId,
            'method' => $this->method,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Backup job failed', [
            'database_server_id' => $this->databaseServerId,
            'method' => $this->method,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // If snapshot was pre-created, mark its job as failed
        if ($this->snapshotId) {
            $snapshot = Snapshot::with('job')->find($this->snapshotId);
            if ($snapshot && $snapshot->job->status !== 'failed') {
                $snapshot->job->markFailed($exception);
            }
        }
    }
}
