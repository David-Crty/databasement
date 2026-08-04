<?php

namespace App\Jobs;

use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\VolumeTransferException;
use App\Facades\AppConfig;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;
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
     * Refuse to dump the same snapshot twice at once.
     *
     * QueueTimeouts keeps retry_after above the job timeout, so a re-delivery
     * mid-run should no longer happen. This is the second line of defence.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [QueueTimeouts::overlapGuard($this->snapshotId, $this->timeout)];
    }

    /**
     * Execute the job.
     */
    public function handle(BackupTask $backupTask): void
    {
        $snapshot = Snapshot::with(['job', 'files.volume', 'backup', 'databaseServer.sshConfig'])->findOrFail($this->snapshotId);
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;

        // Update job with queue job ID for tracking (guard for dispatchSync)
        if ($this->job) {
            $job->update(['job_id' => $this->job->getJobId()]);
        }

        try {
            $job->markRunning();

            $attemptInfo = $this->job ? " (attempt {$this->attempts()}/{$this->tries})" : '';
            $job->log("Starting backup for database: {$snapshot->database_name}{$attemptInfo}", 'info');

            // Snapshot::$backup may be null for orphaned snapshots (their
            // backup config was removed after the snapshot was taken).
            $backupPath = $snapshot->backup instanceof \App\Models\Backup
                ? ($snapshot->backup->path ?? '')
                : '';

            // The snapshot's own file rows are the source of truth for the
            // run's targets. On a retry, copies that already uploaded
            // successfully are skipped instead of re-uploaded.
            $targetFiles = $snapshot->files->filter(
                fn (SnapshotFile $file) => $file->status !== SnapshotFileStatus::Completed,
            )->whenEmpty(fn () => $snapshot->files);

            $config = new BackupConfig(
                database: DatabaseConnectionConfig::fromServer($databaseServer),
                volumes: array_values($targetFiles->map(
                    fn (SnapshotFile $file) => VolumeConfig::fromVolume($file->volume, $file->volume->usedStorageBytes()),
                )->all()),
                databaseName: $snapshot->database_name,
                workingDirectory: FilesystemSupport::createWorkingDirectory('backup', $snapshot->id),
                backupPath: $backupPath,
                postBackupScript: AppConfig::get('backup.post_backup_script'),
            );

            $result = $backupTask->execute($config, $job);

            $this->persistResult($snapshot, $result);

            $job->markCompleted();

            app(NotificationService::class)->notifyBackupSuccess($snapshot);

            // Notify-only storage limit: the backup was uploaded despite
            // exceeding a volume's limit, so alert every configured channel.
            foreach ($result->storageWarnings() as $warning) {
                app(NotificationService::class)->notifyStorageLimitWarning($snapshot, $warning->storageWarning ?? '', $warning->volumeName);
            }

            Log::info('Backup completed successfully', [
                'snapshot_id' => $this->snapshotId,
                'database_server_id' => $databaseServer->id,
                'method' => $snapshot->method,
            ]);
        } catch (VolumeTransferException $e) {
            // At least one upload failed. The successful copies are still
            // recorded so their files stay tracked, then the job is failed.
            $this->persistResult($snapshot, $e->result);

            $job->log("Backup failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
            ]);
            $job->markFailed($e);

            if ($e->allFailuresAreQuota()) {
                // Over-quota volumes won't free up on their own — fail
                // immediately (no retry). The custom message reaches the user
                // via the failure notification.
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (\Throwable $e) {
            $job->log("Backup failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $job->markFailed($e);

            throw $e;
        }
    }

    /**
     * Persist the archive fields and per-volume upload outcomes onto the
     * snapshot and its copy rows.
     */
    private function persistResult(Snapshot $snapshot, BackupResult $result): void
    {
        $snapshot->update([
            'filename' => $result->filename,
            'file_size' => $result->fileSize,
            'checksum' => $result->checksum,
        ]);

        foreach ($result->volumeResults as $volumeResult) {
            $file = $snapshot->files->firstWhere('volume_id', $volumeResult->volumeId);
            if ($file === null) {
                continue;
            }

            if ($volumeResult->status === SnapshotFileStatus::Completed) {
                $file->update([
                    'status' => SnapshotFileStatus::Completed,
                    'file_exists' => true,
                    'file_verified_at' => now(),
                    'error' => null,
                ]);
            } else {
                $file->update([
                    'status' => SnapshotFileStatus::Failed,
                    'error' => $volumeResult->error,
                ]);
            }
        }
    }

    /**
     * Handle a job failure (called by Laravel queue after all retries exhausted).
     */
    public function failed(\Throwable $exception): void
    {
        $snapshot = Snapshot::with(['databaseServer'])->find($this->snapshotId);
        if ($snapshot === null) {
            return;
        }

        app(NotificationService::class)->notifyBackupFailed($snapshot, $exception);
    }
}
