<?php

namespace App\Jobs;

use App\Facades\AppConfig;
use App\Models\Restore;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\RestoreConfig;
use App\Services\Backup\DTO\VolumeConfig;
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
        $restore = Restore::with(['job', 'snapshot.volume', 'snapshot.databaseServer', 'targetServer.sshConfig'])
            ->findOrFail($this->restoreId);
        $targetServer = $restore->targetServer;
        $snapshot = $restore->snapshot;
        $job = $restore->job;

        // Update job with queue job ID for tracking (guard for dispatchSync)
        if ($this->job) {
            $job->update(['job_id' => $this->job->getJobId()]);
        }

        try {
            $job->markRunning();

            $attemptInfo = $this->job ? " (attempt {$this->attempts()}/{$this->tries})" : '';
            $job->log("Starting restore operation{$attemptInfo}", 'info');

            $config = new RestoreConfig(
                targetServer: DatabaseConnectionConfig::fromServer($targetServer),
                snapshotVolume: VolumeConfig::fromVolume($snapshot->volume),
                snapshotFilename: $snapshot->filename,
                snapshotFileSize: $snapshot->file_size,
                snapshotCompressionType: $snapshot->compression_type,
                snapshotDatabaseType: $snapshot->database_type,
                snapshotDatabaseName: $snapshot->database_name,
                schemaName: $restore->schema_name,
                workingDirectory: FilesystemSupport::createWorkingDirectory('restore', $restore->id),
            );

            $restoreTask->execute($config, $job);

            $job->markCompleted();

            Log::info('Restore completed successfully', [
                'restore_id' => $this->restoreId,
                'snapshot_id' => $restore->snapshot_id,
                'target_server_id' => $restore->target_server_id,
                'schema_name' => $restore->schema_name,
            ]);
        } catch (\Throwable $e) {
            $job->log("Restore failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $job->markFailed($e);

            throw $e;
        }
    }

    /**
     * Handle a job failure (called by Laravel queue after all retries exhausted).
     */
    public function failed(\Throwable $exception): void
    {
        $restore = Restore::with(['targetServer', 'snapshot'])->findOrFail($this->restoreId);
        app(FailureNotificationService::class)->notifyRestoreFailed($restore, $exception);
    }
}
