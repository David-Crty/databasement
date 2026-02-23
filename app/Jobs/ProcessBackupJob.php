<?php

namespace App\Jobs;

use App\Facades\AppConfig;
use App\Models\Snapshot;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;
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
        $snapshot = Snapshot::with(['job', 'volume', 'databaseServer.backup', 'databaseServer.sshConfig'])->findOrFail($this->snapshotId);
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

            if (! $databaseServer->backup) {
                throw new \RuntimeException("Backup not configured for server: {$databaseServer->name}");
            }

            $config = new BackupConfig(
                database: DatabaseConnectionConfig::fromServer($databaseServer),
                volume: VolumeConfig::fromVolume($snapshot->volume),
                databaseName: $snapshot->database_name,
                workingDirectory: FilesystemSupport::createWorkingDirectory('backup', $snapshot->id),
                backupPath: $databaseServer->backup->path ?? '',
            );

            $result = $backupTask->execute($config, $job);

            $snapshot->update([
                'filename' => $result->filename,
                'file_size' => $result->fileSize,
                'checksum' => $result->checksum,
                'file_verified_at' => now(),
            ]);

            $job->markCompleted();

            Log::info('Backup completed successfully', [
                'snapshot_id' => $this->snapshotId,
                'database_server_id' => $databaseServer->id,
                'method' => $snapshot->method,
            ]);
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
     * Handle a job failure (called by Laravel queue after all retries exhausted).
     */
    public function failed(\Throwable $exception): void
    {
        $snapshot = Snapshot::with(['databaseServer'])->findOrFail($this->snapshotId);
        app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);
    }
}
