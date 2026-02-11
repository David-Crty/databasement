<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\ConnectionException;
use App\Exceptions\Backup\RestoreException;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Facades\AppConfig;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\Concerns\UsesSshTunnel;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\SshTunnelService;
use App\Support\FilesystemSupport;
use App\Support\Formatters;
use PDO;
use PDOException;

class RestoreTask
{
    use UsesSshTunnel;

    public function __construct(
        private readonly MysqlDatabase $mysqlDatabase,
        private readonly PostgresqlDatabase $postgresqlDatabase,
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

            if ($targetServer->database_type === DatabaseType::SQLITE) {
                // SQLite: simply copy the file to the target path
                $job->log('Restoring SQLite database', 'info', [
                    'source_database' => $snapshot->database_name,
                    'target_path' => $targetServer->sqlite_path,
                ]);
                $this->restoreSqliteDatabase($workingFile, $targetServer->sqlite_path);
            } else {
                $this->prepareDatabase($targetServer, $restore->schema_name, $job);
                $this->configureDatabaseInterface($targetServer, $restore->schema_name);
                $job->log('Restoring database from snapshot', 'info', [
                    'source_database' => $snapshot->database_name,
                    'target_database' => $restore->schema_name,
                ]);
                $this->restoreDatabase($targetServer, $workingFile);
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

    protected function prepareDatabase(DatabaseServer $targetServer, string $schemaName, BackupJob $job): void
    {
        try {
            $pdo = $this->createPdoConnection($targetServer);

            match ($targetServer->database_type) {
                DatabaseType::MYSQL => $this->prepareMysqlDatabase($pdo, $schemaName, $job),
                DatabaseType::POSTGRESQL => $this->preparePostgresqlDatabase($pdo, $schemaName, $job),
                default => throw new UnsupportedDatabaseTypeException($targetServer->database_type->value),
            };
        } catch (PDOException $e) {
            throw new ConnectionException("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Create a PDO connection, using tunnel endpoint if active.
     */
    private function createPdoConnection(DatabaseServer $server, ?string $database = null): PDO
    {
        // When tunnel is not active, use the standard connection method
        if ($this->tunnelEndpoint === null) {
            return $server->database_type->createPdo($server, $database);
        }

        // Build DSN using tunnel endpoint
        $host = $this->getConnectionHost($server);
        $port = $this->getConnectionPort($server);
        $dsn = match ($server->database_type) {
            DatabaseType::MYSQL => $database
                ? sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database)
                : sprintf('mysql:host=%s;port=%d', $host, $port),
            DatabaseType::POSTGRESQL => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $database ?? 'postgres'
            ),
            default => throw new UnsupportedDatabaseTypeException($server->database_type->value),
        };

        return new PDO($dsn, $server->username, $server->getDecryptedPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 30,
        ]);
    }

    private function prepareMysqlDatabase(PDO $pdo, string $schemaName, BackupJob $job): void
    {
        // Drop database if exists
        $dropCommand = "DROP DATABASE IF EXISTS `{$schemaName}`";
        $job->logCommand($dropCommand, null, 0);
        $pdo->exec($dropCommand);

        // Create new database
        $createCommand = "CREATE DATABASE `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $job->logCommand($createCommand, null, 0);
        $pdo->exec($createCommand);
    }

    private function preparePostgresqlDatabase(PDO $pdo, string $schemaName, BackupJob $job): void
    {
        // Check if database exists
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$schemaName]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $job->log('Database exists, terminating existing connections', 'info');

            // Terminate existing connections to the database
            $terminateCommand = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$schemaName}' AND pid <> pg_backend_pid()";
            $job->logCommand($terminateCommand, null, 0);
            $pdo->exec($terminateCommand);

            // Drop the database
            $dropCommand = "DROP DATABASE IF EXISTS \"{$schemaName}\"";
            $job->logCommand($dropCommand, null, 0);
            $pdo->exec($dropCommand);
        }

        // Create new database
        $createCommand = "CREATE DATABASE \"{$schemaName}\"";
        $job->logCommand($createCommand, null, 0);
        $pdo->exec($createCommand);
    }

    private function restoreDatabase(DatabaseServer $targetServer, string $inputPath): void
    {
        $command = match ($targetServer->database_type) {
            DatabaseType::MYSQL => $this->mysqlDatabase->getRestoreCommandLine($inputPath),
            DatabaseType::POSTGRESQL => $this->postgresqlDatabase->getRestoreCommandLine($inputPath),
            default => throw new UnsupportedDatabaseTypeException($targetServer->database_type->value),
        };

        $this->shellProcessor->process($command);
    }

    /**
     * Restore SQLite database by copying file to target path.
     */
    private function restoreSqliteDatabase(string $sourcePath, string $targetPath): void
    {
        $command = sprintf("cp '%s' '%s' && chmod 0666 '%s'", $sourcePath, $targetPath, $targetPath);
        $this->shellProcessor->process($command);
    }

    private function configureDatabaseInterface(DatabaseServer $targetServer, string $schemaName): void
    {
        $config = [
            'host' => $this->getConnectionHost($targetServer),
            'port' => $this->getConnectionPort($targetServer),
            'user' => $targetServer->username,
            'pass' => $targetServer->getDecryptedPassword(),
            'database' => $schemaName,
        ];

        match ($targetServer->database_type) {
            DatabaseType::MYSQL => $this->mysqlDatabase->setConfig($config),
            DatabaseType::POSTGRESQL => $this->postgresqlDatabase->setConfig($config),
            default => throw new UnsupportedDatabaseTypeException($targetServer->database_type->value),
        };
    }
}
