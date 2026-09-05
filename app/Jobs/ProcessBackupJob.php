<?php

namespace App\Jobs;

use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\VolumeTransferException;
use App\Facades\AppConfig;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\S3BucketBackupEngine;
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

            // S3/object-storage servers are copied as buckets, not SQL-dumped.
            if ($databaseServer->database_type->isObjectStorage()) {
                $this->runObjectStorageBackup($snapshot, $databaseServer, $job);

                return;
            }

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
     * Run a bucket-copy backup for an S3/object-storage snapshot: pick the
     * folder scope, drive S3BucketBackupEngine (which builds + uploads the
     * archive to every target volume) and persist the outcome it returned.
     */
    private function runObjectStorageBackup(Snapshot $snapshot, \App\Models\DatabaseServer $databaseServer, \App\Models\BackupJob $job): void
    {
        $attemptInfo = $this->job ? " (attempt {$this->attempts()}/{$this->tries})" : '';
        $job->log("Starting bucket backup for folder: {$snapshot->database_name}{$attemptInfo}", 'info');

        $handler = app(DatabaseProvider::class)
            ->makeForServer($databaseServer, $snapshot->database_name, $databaseServer->host ?? '', $databaseServer->port ?? 0);

        if (! $handler instanceof \App\Services\Backup\Databases\S3Database) {
            throw new \RuntimeException('Expected an S3 database handler for bucket backup.');
        }
        $engine = app(S3BucketBackupEngine::class);

        // Retry support: skip volumes whose copy already uploaded successfully.
        $targets = array_values(
            $snapshot->files
                ->filter(fn (SnapshotFile $file) => $file->status !== SnapshotFileStatus::Completed)
                ->whenEmpty(fn () => $snapshot->files)
                ->values()
                ->map(fn (SnapshotFile $file) => VolumeConfig::fromVolume($file->volume, $file->volume->usedStorageBytes()))
                ->all()
        );

        $outcome = $engine->run(
            snapshot: $snapshot,
            source: $handler->getFilesystem(),
            scope: rtrim((string) $snapshot->database_name, '/'),
            targets: $targets,
            logger: $job,
        );

        $this->persistS3Outcome($snapshot, $outcome);
        $this->applyS3VolumeResults($snapshot, $outcome['volume_results']);

        $failures = array_values(array_filter(
            $outcome['volume_results'],
            fn (\App\Services\Backup\DTO\VolumeTransferResult $r) => $r->status === SnapshotFileStatus::Failed,
        ));

        if ($failures !== []) {
            // Successful per-volume copies were recorded above so a retry does
            // not re-upload them, but the run is not complete until every target
            // volume holds the archive. Throw so `handle()` marks the job failed
            // and the queue retries within `backup.job_tries`, then `failed()`
            // notifies only once the retries are exhausted. Returning here would
            // acknowledge the attempt and bypass retries entirely.
            $names = implode(', ', array_map(fn ($r) => $r->volumeName, $failures));
            $message = __('Bucket upload failed for volume(s): :volumes', ['volumes' => $names]);
            $job->log($message, 'error');

            throw new \RuntimeException($message);
        }

        $job->log(sprintf(
            'Bucket backup completed: %s run, %d archive/files, checksum %s',
            $outcome['run_kind']->value,
            count($outcome['object_files']),
            substr($outcome['checksum'], 0, 16).'...',
        ), 'success');

        $job->markCompleted();
        app(NotificationService::class)->notifyBackupSuccess($snapshot);
        Log::info('Bucket backup completed successfully', [
            'snapshot_id' => $snapshot->id,
            'database_server_id' => $databaseServer->id,
        ]);
    }

    /**
     * Write the archive run-level fields + object rows + effective state
     * produced by S3BucketBackupEngine onto the Snapshot.
     *
     * @param  array{run_kind: \App\Enums\RunKind, full_snapshot_id: string|null, filename: string, file_size: int, checksum: string, object_files: array<int, array<string, mixed>>, object_state: array<string, array{size: int, mtime: int}>}  $outcome
     */
    private function persistS3Outcome(Snapshot $snapshot, array $outcome): void
    {
        $snapshot->update([
            'run_kind' => $outcome['run_kind'],
            'full_snapshot_id' => $outcome['full_snapshot_id'],
            'filename' => $outcome['filename'],
            'checksum' => $outcome['checksum'],
            'file_size' => $outcome['file_size'],
            'metadata' => array_merge($snapshot->metadata ?? [], [
                S3BucketBackupEngine::META_STATE_KEY => $outcome['object_state'],
            ]),
        ]);

        foreach ($outcome['object_files'] as $row) {
            $snapshot->objectFiles()->create($row);
        }
    }

    /**
     * Mark each per-volume copy row Completed/Failed from the engine results.
     *
     * @param  list<\App\Services\Backup\DTO\VolumeTransferResult>  $results
     */
    private function applyS3VolumeResults(Snapshot $snapshot, array $results): void
    {
        foreach ($results as $result) {
            $file = $snapshot->files->firstWhere('volume_id', $result->volumeId);
            if ($file === null) {
                continue;
            }
            $isOk = $result->status === SnapshotFileStatus::Completed;
            $file->update([
                'status' => $result->status,
                'file_exists' => $isOk,
                'file_verified_at' => $isOk ? now() : $file->file_verified_at,
                'error' => $isOk ? null : $result->error,
            ]);
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
