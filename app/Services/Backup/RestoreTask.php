<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\RestoreException;
use App\Facades\AppConfig;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use App\Support\FilesystemSupport;
use App\Support\Formatters;

class RestoreTask
{
    use UsesSshTunnel;

    public function __construct(
        private readonly DatabaseProvider $databaseProvider,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly CompressorFactory $compressorFactory,
        private readonly SshTunnelService $sshTunnelService,
    ) {}

    protected function getSshTunnelService(): SshTunnelService
    {
        return $this->sshTunnelService;
    }

    /**
     * Restore a snapshot to a target database server
     *
     * @throws \Exception
     */
    public function run(Restore $restore, ?int $attempt = null, ?int $maxAttempts = null): Restore
    {
        $targetServer = $restore->targetServer;
        $snapshot = $restore->snapshot;
        $job = $restore->job;
        try {
            AppConfig::ensureBackupTmpFolderExists();
            $this->validateCompatibility($targetServer, $snapshot);

            if ($targetServer->database_type === DatabaseType::REDIS) {
                throw new RestoreException('Automated restore is not supported for Redis/Valkey. Please restore manually.');
            }

            $this->shellProcessor->setLogger($job);
            $workingDirectory = FilesystemSupport::createWorkingDirectory('restore', $restore->id);

            $job->markRunning();

            $attemptInfo = $attempt && $maxAttempts ? " (attempt {$attempt}/{$maxAttempts})" : '';
            $job->log("Starting restore operation{$attemptInfo}", 'info', [
                'target_database_server' => [
                    'id' => $targetServer->id,
                    'name' => $targetServer->name,
                    'database_name' => $restore->schema_name,
                    'database_type' => $targetServer->database_type,
                ],
                'snapshot' => [
                    'id' => $snapshot->id,
                    'database_name' => $snapshot->database_name,
                    'database_server' => [
                        'id' => $snapshot->databaseServer->id,
                        'name' => $snapshot->databaseServer->name,
                        'database_type' => $snapshot->databaseServer->database_type,
                    ],
                ],
            ]);

            if ($targetServer->requiresSshTunnel()) {
                $this->establishSshTunnel($targetServer, $job);
            }

            $humanFileSize = Formatters::humanFileSize($snapshot->file_size);
            $compressedFile = $workingDirectory.'/snapshot.'.$snapshot->compression_type->extension();
            $compressor = $this->compressorFactory->make($snapshot->compression_type);

            // Download snapshot from volume
            $job->log("Downloading snapshot ({$humanFileSize}) from volume: {$snapshot->volume->name}", 'info', [
                'volume_type' => $snapshot->volume->type,
                'source' => $snapshot->filename,
                'destination' => $compressedFile,
                'compression_type' => $snapshot->compression_type->value,
            ]);
            $transferStart = microtime(true);
            $this->filesystemProvider->download($snapshot, $compressedFile);
            $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));
            $job->log('Download completed successfully in '.$transferDuration, 'success');

            // Decompress the archive
            $workingFile = $compressor->decompress($compressedFile);

            $database = $this->databaseProvider->makeForServer(
                $targetServer,
                $restore->schema_name,
                $this->getConnectionHost($targetServer),
                $this->getConnectionPort($targetServer),
                $snapshot->database_name,
            );

            $this->prepareDatabase($database, $restore->schema_name, $job);

            $job->log('Restoring database from snapshot', 'info', [
                'source_database' => $snapshot->database_name,
                'target_database' => $restore->schema_name,
            ]);

            $result = $database->restore($workingFile);
            if ($result->command !== null) {
                $this->shellProcessor->process($result->command);
            }
            if ($result->log !== null) {
                $job->log($result->log->message, $result->log->level, $result->log->context ?? []);
            }

            // Mark job as completed
            $job->markCompleted();

            return $restore;
        } catch (\Throwable $e) {
            $job->log("Restore failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $job->markFailed($e);
            throw $e;
        } finally {
            // Close SSH tunnel if active
            $this->closeSshTunnel($job);

            // Clean up working directory and all files within (safety net, Job also cleans up on failure)
            if (isset($workingDirectory) && is_dir($workingDirectory)) {
                $job->log('Cleaning up temporary files', 'info');
                FilesystemSupport::cleanupDirectory($workingDirectory);
            }
        }
    }

    private function validateCompatibility(DatabaseServer $targetServer, Snapshot $snapshot): void
    {
        if ($targetServer->database_type !== $snapshot->database_type) {
            throw new RestoreException(
                "Cannot restore {$snapshot->database_type->value} snapshot to {$targetServer->database_type->value} server"
            );
        }
    }

    protected function prepareDatabase(DatabaseInterface $database, string $schemaName, BackupJob $job): void
    {
        $database->prepareForRestore($schemaName, $job);
    }
}
