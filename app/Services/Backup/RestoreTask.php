<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Enums\DatabaseType;
use App\Exceptions\Backup\RestoreException;
use App\Facades\AppConfig;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\RestoreConfig;
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
     * Execute the core restore workflow: download, decompress, prepare, restore.
     *
     * This is the pure restore engine with no model persistence.
     * `ProcessRestoreJob` delegates to this method.
     */
    public function execute(RestoreConfig $config, BackupLogger $logger): void
    {
        $this->shellProcessor->setLogger($logger);
        $target = $config->targetServer;

        try {
            AppConfig::ensureBackupTmpFolderExists();

            $this->validateCompatibility($target->databaseType, $config->snapshotDatabaseType);

            if ($target->databaseType === DatabaseType::REDIS) {
                throw new RestoreException('Automated restore is not supported for Redis/Valkey. Please restore manually.');
            }

            if ($target->requiresSshTunnel()) {
                $this->establishSshTunnel($target, $logger);
            }

            $humanFileSize = Formatters::humanFileSize($config->snapshotFileSize);
            $compressedFile = $config->workingDirectory.'/snapshot.'.$config->snapshotCompressionType->extension();
            $compressor = $this->compressorFactory->make($config->snapshotCompressionType);

            // Download snapshot from volume
            $logger->log("Downloading snapshot ({$humanFileSize}) from volume: {$config->snapshotVolume->name}", 'info', [
                'volume_type' => $config->snapshotVolume->type,
                'source' => $config->snapshotFilename,
                'destination' => $compressedFile,
                'compression_type' => $config->snapshotCompressionType->value,
            ]);
            $transferStart = microtime(true);
            $this->filesystemProvider->downloadFromConfig($config->snapshotVolume, $config->snapshotFilename, $compressedFile);
            $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));
            $logger->log('Download completed successfully in '.$transferDuration, 'success');

            // Decompress the archive
            $workingFile = $compressor->decompress($compressedFile);

            $database = $this->databaseProvider->makeFromConfig(
                $target,
                $config->schemaName,
                $this->getConnectionHost($target),
                $this->getConnectionPort($target),
                $config->snapshotDatabaseName,
            );

            $this->prepareDatabase($database, $config->schemaName, $logger);

            $logger->log('Restoring database from snapshot', 'info', [
                'source_database' => $config->snapshotDatabaseName,
                'target_database' => $config->schemaName,
            ]);

            $result = $database->restore($workingFile);
            if ($result->command !== null) {
                $this->shellProcessor->process($result->command);
            }
            if ($result->log !== null) {
                $logger->log($result->log->message, $result->log->level, $result->log->context ?? []);
            }

            // Mark job as completed
            $logger->log('Restore completed successfully', 'success');
        } finally {
            // Close SSH tunnel if active
            $this->closeSshTunnel($logger);

            // Clean up working directory and all files within
            if (is_dir($config->workingDirectory)) {
                $logger->log('Cleaning up temporary files', 'info');
                FilesystemSupport::cleanupDirectory($config->workingDirectory);
            }
        }
    }

    private function validateCompatibility(DatabaseType $targetType, DatabaseType $snapshotType): void
    {
        if ($targetType !== $snapshotType) {
            throw new RestoreException(
                "Cannot restore {$snapshotType->value} snapshot to {$targetType->value} server"
            );
        }
    }

    protected function prepareDatabase(DatabaseInterface $database, string $schemaName, BackupLogger $logger): void
    {
        $database->prepareForRestore($schemaName, $logger);
    }
}
