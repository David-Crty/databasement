<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use App\Support\FilesystemSupport;
use App\Support\Formatters;

class BackupTask
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

    public function setLogger(BackupLogger $logger): void
    {
        $this->shellProcessor->setLogger($logger);
    }

    public function run(Snapshot $snapshot, ?int $attempt = null, ?int $maxAttempts = null): Snapshot
    {
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;

        try {
            AppConfig::ensureBackupTmpFolderExists();
            $workingDirectory = FilesystemSupport::createWorkingDirectory('backup', $snapshot->id);

            $job->markRunning();

            $attemptInfo = $attempt && $maxAttempts ? " (attempt {$attempt}/{$maxAttempts})" : '';
            $job->log("Starting backup for database: {$snapshot->database_name}{$attemptInfo}", 'info');

            $result = $this->execute(
                server: $databaseServer,
                databaseName: $snapshot->database_name,
                volume: $snapshot->volume,
                logger: $job,
                workingDirectory: $workingDirectory,
                backupPath: $databaseServer->backup->path ?? '',
            );

            $snapshot->update([
                'filename' => $result->filename,
                'file_size' => $result->fileSize,
                'checksum' => $result->checksum,
                'file_verified_at' => now(),
            ]);

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
        }
    }

    /**
     * Execute the core backup workflow: dump, compress, transfer, checksum.
     *
     * This is the pure backup engine with no model persistence.
     * Both `run()` and `AgentRunCommand` delegate to this method.
     *
     * @param  callable|null  $onProgress  Called after dump, compression, and transfer steps
     */
    public function execute(
        DatabaseServer $server,
        string $databaseName,
        Volume $volume,
        BackupLogger $logger,
        string $workingDirectory,
        string $backupPath = '',
        ?CompressionType $compressionType = null,
        ?int $compressionLevel = null,
        ?callable $onProgress = null,
    ): BackupResult {
        $this->shellProcessor->setLogger($logger);

        try {
            if ($server->requiresSshTunnel()) {
                $this->establishSshTunnel($server, $logger);
            }

            $workingFile = $workingDirectory.'/dump.'.$server->database_type->dumpExtension();

            // Database dump
            $database = $this->databaseProvider->makeForServer(
                $server,
                $databaseName,
                $this->getConnectionHost($server),
                $this->getConnectionPort($server),
            );

            $result = $database->dump($workingFile);
            if ($result->command !== null) {
                $this->shellProcessor->process($result->command);
            }
            if ($result->log !== null) {
                $logger->log($result->log->message, $result->log->level, $result->log->context ?? []);
            }

            if ($onProgress !== null) {
                $onProgress();
            }

            // Compress
            $compressor = $this->compressorFactory->make($compressionType, $compressionLevel);
            $archive = $compressor->compress($workingFile);
            $fileSize = filesize($archive);
            if ($fileSize === false) {
                throw new \RuntimeException("Failed to get file size for: {$archive}");
            }

            if ($onProgress !== null) {
                $onProgress();
            }

            // Generate filename and transfer
            $humanFileSize = Formatters::humanFileSize($fileSize);
            $filename = $this->generateFilename($server->name, $databaseName, $server->database_type->dumpExtension(), $compressor, $backupPath);
            $logger->log("Transferring backup ({$humanFileSize}) to volume: {$volume->name}", 'info', [
                'volume_type' => $volume->type,
                'source' => $archive,
                'destination' => $filename,
            ]);
            $transferStart = microtime(true);
            $this->filesystemProvider->transfer($volume, $archive, $filename);
            $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));
            $logger->log('Transfer completed successfully in '.$transferDuration, 'success');

            if ($onProgress !== null) {
                $onProgress();
            }

            // Checksum
            $checksum = hash_file('sha256', $archive);
            if ($checksum === false) {
                throw new \RuntimeException("Failed to calculate checksum for: {$archive}");
            }

            $logger->log('Backup completed successfully', 'success', [
                'file_size' => $humanFileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'filename' => $filename,
            ]);

            return new BackupResult($filename, $fileSize, $checksum);
        } finally {
            $this->closeSshTunnel($logger);

            if (is_dir($workingDirectory)) {
                $logger->log('Cleaning up temporary files', 'info');
                FilesystemSupport::cleanupDirectory($workingDirectory);
            }
        }
    }

    /**
     * Generate the filename to store in the volume.
     * Includes optional path prefix for organizing backups.
     */
    private function generateFilename(string $serverName, string $databaseName, string $baseExtension, CompressorInterface $compressor, string $backupPath): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $sanitizedServerName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $serverName);
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName);
        $compressionExtension = $compressor->getExtension();

        $filename = sprintf('%s-%s-%s.%s.%s', $sanitizedServerName, $sanitizedDbName, $timestamp, $baseExtension, $compressionExtension);

        if (! empty($backupPath)) {
            $path = $this->resolveDateVariables(trim($backupPath, '/'));
            $filename = $path.'/'.$filename;
        }

        return $filename;
    }

    private function resolveDateVariables(string $path): string
    {
        $date = now();

        return str_replace(
            ['{year}', '{month}', '{day}'],
            [$date->format('Y'), $date->format('m'), $date->format('d')],
            $path
        );
    }
}
