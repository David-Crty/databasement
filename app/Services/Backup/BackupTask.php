<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
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

    /**
     * Execute the core backup workflow: dump, compress, transfer, checksum.
     *
     * This is the pure backup engine with no model persistence.
     * `ProcessBackupJob` delegates to this method.
     *
     * @param  callable|null  $onProgress  Called after dump, compression, and transfer steps
     */
    public function execute(
        BackupConfig $config,
        BackupLogger $logger,
        ?callable $onProgress = null,
    ): BackupResult {
        $this->shellProcessor->setLogger($logger);
        $db = $config->database;

        try {
            if ($db->requiresSshTunnel()) {
                $this->establishSshTunnel($db, $logger);
            }

            $workingFile = $config->workingDirectory.'/dump.'.$db->databaseType->dumpExtension();

            // Database dump
            $database = $this->databaseProvider->makeFromConfig(
                $db,
                $config->databaseName,
                $this->getConnectionHost($db),
                $this->getConnectionPort($db),
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
            $compressor = $this->compressorFactory->make($config->compressionType, $config->compressionLevel);
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
            $filename = $this->generateFilename($db->serverName, $config->databaseName, $db->databaseType->dumpExtension(), $compressor, $config->backupPath);
            $logger->log("Transferring backup ({$humanFileSize}) to volume: {$config->volume->name}", 'info', [
                'volume_type' => $config->volume->type,
                'source' => $archive,
                'destination' => $filename,
            ]);
            $transferStart = microtime(true);
            $this->filesystemProvider->transferFromConfig($config->volume, $archive, $filename);
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

            if (is_dir($config->workingDirectory)) {
                $logger->log('Cleaning up temporary files', 'info');
                FilesystemSupport::cleanupDirectory($config->workingDirectory);
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
        $sanitizedServerName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $serverName) ?? $serverName;
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName) ?? $databaseName;
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
