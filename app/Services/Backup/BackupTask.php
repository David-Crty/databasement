<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\StorageQuotaExceededException;
use App\Exceptions\Backup\VolumeTransferException;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\DTO\VolumeTransferResult;
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
        private readonly PostScriptRunner $postScriptRunner,
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

            $dumpFormat = is_string($value = ($db->extraConfig['dump_format'] ?? null)) ? $value : null;
            $workingFile = $config->workingDirectory.'/dump.'.$db->databaseType->dumpExtension($dumpFormat);

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
            $compressor = $this->compressorFactory->make($config->compressionType, $config->compressionLevel, $config->compressionMultithread);
            $archive = $compressor->compress($workingFile);
            $fileSize = filesize($archive);
            if ($fileSize === false) {
                throw new \RuntimeException("Failed to get file size for: {$archive}");
            }

            if ($onProgress !== null) {
                $onProgress();
            }

            // Generate the filename once — the same archive is uploaded to
            // every target volume under the same name.
            $humanFileSize = Formatters::humanFileSize($fileSize);
            $filename = $this->generateFilename($db->serverName, $config->databaseName, $db->databaseType->dumpExtension($dumpFormat), $compressor, $config->backupPath);

            $volumeResults = [];
            foreach ($config->volumes as $volume) {
                $volumeResults[] = $this->transferToVolume($volume, $archive, $filename, $fileSize, $humanFileSize, $logger);
            }

            if ($onProgress !== null) {
                $onProgress();
            }

            // Checksum
            $checksum = hash_file('sha256', $archive);
            if ($checksum === false) {
                throw new \RuntimeException("Failed to calculate checksum for: {$archive}");
            }

            $result = new BackupResult($filename, $fileSize, $checksum, $volumeResults);

            // Any failed upload fails the whole job — but only after every
            // volume was attempted, so the successful copies are recorded.
            if ($result->hasFailures()) {
                $failedNames = array_map(
                    fn (VolumeTransferResult $failure) => $failure->volumeName,
                    $result->failedResults(),
                );

                throw new VolumeTransferException(
                    $result,
                    __('Upload failed for volume(s): :volumes', ['volumes' => implode(', ', $failedNames)]),
                );
            }

            $logger->log('Backup completed successfully', 'success', [
                'file_size' => $humanFileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'filename' => $filename,
            ]);

            $this->postScriptRunner->run(
                $this->shellProcessor,
                $logger,
                'post-backup-script',
                $config->postBackupScript,
                $config->workingDirectory,
                [
                    'BACKUP_SERVER_ID' => $db->serverId,
                    'BACKUP_SERVER_NAME' => $db->serverName,
                    'BACKUP_DATABASE_NAME' => $config->databaseName,
                    'BACKUP_DATABASE_TYPE' => $db->databaseType->value,
                    'BACKUP_FILENAME' => $filename,
                    'BACKUP_FILE_SIZE' => (string) $fileSize,
                    'BACKUP_CHECKSUM' => $checksum,
                    'BACKUP_VOLUME_NAME' => implode(', ', array_map(
                        fn (VolumeConfig $volume) => $volume->name,
                        $config->volumes,
                    )),
                ],
            );

            return $result;
        } finally {
            $this->closeSshTunnel($logger);

            if (is_dir($config->workingDirectory)) {
                $logger->log('Cleaning up temporary files', 'info');
                FilesystemSupport::cleanupDirectory($config->workingDirectory);
            }
        }
    }

    /**
     * Upload the archive to one target volume, converting quota blocks and
     * transfer errors into a per-volume result instead of aborting the run —
     * every volume gets its attempt before the caller decides to fail the job.
     */
    private function transferToVolume(
        VolumeConfig $volume,
        string $archive,
        string $filename,
        int $fileSize,
        string $humanFileSize,
        BackupLogger $logger,
    ): VolumeTransferResult {
        try {
            $storageWarning = $this->assertWithinStorageQuota($volume, $fileSize, $logger);
        } catch (StorageQuotaExceededException $e) {
            return new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Failed,
                error: $e->getMessage(),
                quotaExceeded: true,
            );
        }

        $logger->log("Transferring backup ({$humanFileSize}) to volume: {$volume->name}", 'info', [
            'volume_type' => $volume->type,
            'source' => $archive,
            'destination' => $filename,
        ]);

        $transferStart = microtime(true);

        try {
            $this->filesystemProvider->transferFromConfig($volume, $archive, $filename);
        } catch (\Throwable $e) {
            $logger->log("Transfer to volume {$volume->name} failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
            ]);

            return new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Failed,
                error: $e->getMessage(),
            );
        }

        $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));
        $logger->log('Transfer completed successfully in '.$transferDuration, 'success');

        return new VolumeTransferResult(
            volumeId: $volume->id,
            volumeName: $volume->name,
            status: SnapshotFileStatus::Completed,
            storageWarning: $storageWarning,
        );
    }

    /**
     * Enforce a target volume's storage limit before upload when this backup
     * would exceed it. Skipped (returns null) when no limit is set or the current
     * usage is unknown (remote agents).
     *
     * In block mode (default) this throws and the file is never uploaded. In
     * notify-only mode it logs a warning and returns the message so the caller
     * can notify the user while still uploading the backup.
     *
     * @return string|null The overage message when notify-only mode is on and the
     *                     limit was exceeded; null otherwise.
     *
     * @throws StorageQuotaExceededException
     */
    private function assertWithinStorageQuota(VolumeConfig $volume, int $fileSize, BackupLogger $logger): ?string
    {
        $limit = $volume->config['max_storage_bytes'] ?? null;

        if ($limit === null || $volume->usedBytes === null) {
            return null;
        }

        $limit = (int) $limit;
        $projected = $volume->usedBytes + $fileSize;

        if ($projected <= $limit) {
            return null;
        }

        $notifyOnly = (bool) ($volume->config['max_storage_notify_only'] ?? false);

        $context = [
            'volume' => $volume->name,
            'used_bytes' => $volume->usedBytes,
            'file_size' => $fileSize,
            'limit_bytes' => $limit,
        ];

        if ($notifyOnly) {
            $message = __('Storage limit reached for volume ":volume". This backup (:size) brings total usage to :projected, over the :limit limit. The backup was still uploaded (notify-only) — free up space by deleting old snapshots.', [
                'volume' => $volume->name,
                'size' => Formatters::humanFileSize($fileSize),
                'projected' => Formatters::humanFileSize($projected),
                'limit' => Formatters::humanFileSize($limit),
            ]);

            $logger->log($message, 'warning', $context);

            return $message;
        }

        $message = __('Storage limit reached for volume ":volume". This backup (:size) would bring total usage to :projected, over the :limit limit. The file was not uploaded — free up space by deleting old snapshots.', [
            'volume' => $volume->name,
            'size' => Formatters::humanFileSize($fileSize),
            'projected' => Formatters::humanFileSize($projected),
            'limit' => Formatters::humanFileSize($limit),
        ]);

        $logger->log($message, 'error', $context);

        throw new StorageQuotaExceededException($message);
    }

    /**
     * Generate the filename to store in the volume.
     * Includes optional path prefix for organizing backups.
     */
    private function generateFilename(string $serverName, string $databaseName, string $baseExtension, CompressorInterface $compressor, string $backupPath): string
    {
        $timestamp = now()->setTimezone(config('app.display_timezone'))->format('Y-m-d-His');
        $sanitizedServerName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $serverName) ?? $serverName;
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName) ?? $databaseName;
        $compressionExtension = $compressor->getExtension();

        $filename = sprintf('%s-%s-%s.%s.%s', $sanitizedServerName, $sanitizedDbName, $timestamp, $baseExtension, $compressionExtension);

        if (! empty($backupPath)) {
            $path = Formatters::resolveDatePlaceholders(trim($backupPath, '/'));
            $filename = $path.'/'.$filename;
        }

        return $filename;
    }
}
