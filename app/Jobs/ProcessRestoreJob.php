<?php

namespace App\Jobs;

use App\Enums\SnapshotFileStatus;
use App\Facades\AppConfig;
use App\Models\Restore;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\RestoreConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\RestoreTask;
use App\Services\NotificationService;
use App\Support\FilesystemSupport;
use App\Support\QueueTimeouts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * Refuse to run the same restore twice at once.
     *
     * Two workers dropping and recreating the same target database concurrently
     * is worse than a missed retry.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [QueueTimeouts::overlapGuard($this->restoreId, $this->timeout)];
    }

    /**
     * Execute the job.
     */
    public function handle(RestoreTask $restoreTask): void
    {
        $restore = Restore::with(['job', 'snapshot.files.volume', 'snapshot.databaseServer', 'snapshotFile.volume', 'targetServer.sshConfig'])
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

            // Read from the copy the user picked, or auto-pick the first
            // copy still present on its volume.
            $sourceFile = $restore->snapshot_file_id !== null
                ? $restore->snapshotFile
                : $snapshot->primaryFile();

            // A user-picked copy must still belong to this snapshot, have
            // finished uploading, and exist on its volume — otherwise fail
            // rather than silently reading a different volume. With no pick,
            // primaryFile() already returns only an available copy (or null).
            $explicitCopyInvalid = $restore->snapshot_file_id !== null && (
                $sourceFile === null
                || $sourceFile->snapshot_id !== $snapshot->id
                || $sourceFile->status !== SnapshotFileStatus::Completed
                || ! $sourceFile->file_exists
            );

            if ($sourceFile === null || $explicitCopyInvalid) {
                $exception = new \RuntimeException($explicitCopyInvalid
                    ? 'The selected copy of this snapshot is no longer available on its volume.'
                    : 'No existing copy of this snapshot is available on any volume.');
                $job->log("Restore failed: {$exception->getMessage()}", 'error');
                $job->markFailed($exception);

                $this->fail($exception);

                return;
            }

            $job->log("Reading snapshot from volume: {$sourceFile->volume->name}", 'info');

            $config = new RestoreConfig(
                targetServer: DatabaseConnectionConfig::fromServer($targetServer),
                snapshotVolume: VolumeConfig::fromVolume($sourceFile->volume),
                snapshotFilename: $sourceFile->storedFilename(),
                snapshotFileSize: $snapshot->file_size,
                snapshotCompressionType: $snapshot->compression_type,
                snapshotDatabaseType: $snapshot->database_type,
                snapshotDatabaseName: $snapshot->database_name,
                schemaName: $restore->schema_name,
                workingDirectory: FilesystemSupport::createWorkingDirectory('restore', $restore->id),
                forceDatabase: filter_var($restore->getOption('force_database', false), FILTER_VALIDATE_BOOLEAN),
                ownerUser: is_string($value = $restore->getOption('owner_user')) && $value !== '' ? $value : null,
                snapshotDumpFormat: is_string($format = ($snapshot->metadata['dump_format'] ?? null)) ? $format : null,
                snapshotDumpPrivileges: (bool) ($snapshot->metadata['dump_privileges'] ?? false),
                postRestoreScript: AppConfig::get('backup.post_restore_script'),
            );

            $restoreTask->execute($config, $job);

            $job->markCompleted();

            app(NotificationService::class)->notifyRestoreSuccess($restore);

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
        $restore = Restore::with(['targetServer', 'snapshot'])->find($this->restoreId);
        if ($restore === null) {
            return;
        }

        app(NotificationService::class)->notifyRestoreFailed($restore, $exception);
    }
}
