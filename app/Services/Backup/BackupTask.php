<?php

namespace App\Services\Backup;

use App\Facades\AppConfig;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use App\Support\FilesystemSupport;
use App\Support\Formatters;

class BackupTask
{
    use UsesSshTunnel;

    public function __construct(
        private readonly DatabaseFactory $databaseFactory,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly CompressorFactory $compressorFactory,
        private readonly SshTunnelService $sshTunnelService
    ) {}

    protected function getSshTunnelService(): SshTunnelService
    {
        return $this->sshTunnelService;
    }

    public function setLogger(BackupJob $job): void
    {
        $this->shellProcessor->setLogger($job);
    }

    public function run(Snapshot $snapshot, ?int $attempt = null, ?int $maxAttempts = null): Snapshot
    {
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;
        try {
            AppConfig::ensureBackupTmpFolderExists();
            $this->setLogger($job);
            $compressor = $this->compressorFactory->make();
            $workingDirectory = FilesystemSupport::createWorkingDirectory('backup', $snapshot->id);
            $workingFile = $workingDirectory.'/dump.'.$databaseServer->database_type->dumpExtension();

            $job->markRunning();

            // Use the database name from the snapshot (important for multi-database backups)
            $databaseName = $snapshot->database_name;

            $attemptInfo = $attempt && $maxAttempts ? " (attempt {$attempt}/{$maxAttempts})" : '';
            $job->log("Starting backup for database: {$databaseName}{$attemptInfo}", 'info');

            if ($databaseServer->requiresSshTunnel()) {
                $this->establishSshTunnel($databaseServer, $job);
            }

            $this->dumpDatabase($databaseServer, $databaseName, $workingFile);
            $archive = $compressor->compress($workingFile);
            $fileSize = filesize($archive);
            if ($fileSize === false) {
                throw new \RuntimeException("Failed to get file size for: {$archive}");
            }
            $humanFileSize = Formatters::humanFileSize($fileSize);
            $filename = $this->generateFilename($databaseServer, $databaseName, $compressor);
            $job->log("Transferring backup ({$humanFileSize}) to volume: {$snapshot->volume->name}", 'info', [
                'volume_type' => $snapshot->volume->type,
                'source' => $archive,
                'destination' => $filename,
            ]);
            $transferStart = microtime(true);
            $this->filesystemProvider->transfer(
                $snapshot->volume,
                $archive,
                $filename
            );
            $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));
            $job->log('Transfer completed successfully in '.$transferDuration, 'success');

            $checksum = hash_file('sha256', $archive);
            if ($checksum === false) {
                throw new \RuntimeException("Failed to calculate checksum for: {$archive}");
            }

            $job->log('Backup completed successfully', 'success', [
                'file_size' => $humanFileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'filename' => $filename,
            ]);

            // Update snapshot with success
            $snapshot->update([
                'filename' => $filename,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'file_verified_at' => now(),
            ]);

            // Mark job as completed
            $job->markCompleted();

            return $snapshot;
        } catch (\Throwable $e) {
            $job->log("Backup failed: {$e->getMessage()}", 'error', [
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

    private function dumpDatabase(DatabaseServer $databaseServer, string $databaseName, string $outputPath): void
    {
        $database = $this->databaseFactory->makeForServer(
            $databaseServer,
            $databaseName,
            $this->getConnectionHost($databaseServer),
            $this->getConnectionPort($databaseServer),
        );

        $this->shellProcessor->process($database->getDumpCommandLine($outputPath));
    }

    /**
     * Generate the filename to store in the volume.
     * Includes optional path prefix for organizing backups.
     */
    private function generateFilename(DatabaseServer $databaseServer, string $databaseName, CompressorInterface $compressor): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $serverName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->name);
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName);
        $baseExtension = $databaseServer->database_type->dumpExtension();
        $compressionExtension = $compressor->getExtension();

        $filename = sprintf('%s-%s-%s.%s.%s', $serverName, $sanitizedDbName, $timestamp, $baseExtension, $compressionExtension);

        // Prepend path if configured
        $path = $databaseServer->backup?->path;
        if (! empty($path)) {
            $path = trim($path, '/');
            $filename = $path.'/'.$filename;
        }

        return $filename;
    }
}
