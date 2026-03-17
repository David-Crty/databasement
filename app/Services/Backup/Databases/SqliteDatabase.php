<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\DatabaseDumpException;
use App\Exceptions\Backup\RestoreException;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\DTO\DatabaseOperationLog;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\Filesystems\SftpFilesystem;
use League\Flysystem\Filesystem;

class SqliteDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly SftpFilesystem $sftpFilesystem = new SftpFilesystem,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function listDatabases(): array
    {
        return [basename($this->config['sqlite_path'])];
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $sourcePath = $this->config['sqlite_path'];
        $filesystem = $this->getSftpFilesystem();

        if ($filesystem !== null) {
            return $this->dumpRemote($filesystem, $sourcePath, $outputPath);
        }

        // Use sqlite3 .backup command to safely handle WAL mode databases.
        // The .backup command uses SQLite's online backup API, which produces
        // a consistent snapshot even when the database is actively being written to.
        return new DatabaseOperationResult(
            command: sprintf(
                'sqlite3 -readonly %s %s',
                escapeshellarg($sourcePath),
                escapeshellarg('.backup '.$outputPath),
            ),
            log: new DatabaseOperationLog(
                'Backed up local SQLite database',
                'success',
                ['path' => $sourcePath],
            ),
        );
    }

    /**
     * Download a remote SQLite database via SFTP, including WAL/SHM files if present,
     * then use sqlite3 .backup locally to consolidate into a single file.
     *
     * Note: This is a best-effort approach for WAL-mode databases. The sequential SFTP
     * downloads are not atomic — a checkpoint or commit between reads could produce an
     * inconsistent set of files. For guaranteed WAL-safe backups of remote SQLite databases,
     * SSH command execution on the remote host would be required.
     */
    private function dumpRemote(Filesystem $filesystem, string $sourcePath, string $outputPath): DatabaseOperationResult
    {
        $workingDir = dirname($outputPath);
        $localDb = $workingDir.'/sftp_download.db';

        // Download the main database file
        $this->downloadRemoteFile($filesystem, $sourcePath, $localDb);

        // Download WAL and SHM companion files if they exist on the remote
        $walFiles = ['-wal', '-shm'];
        $downloadedCompanions = [];

        foreach ($walFiles as $suffix) {
            $remotePath = $sourcePath.$suffix;

            if (! $filesystem->fileExists($remotePath)) {
                continue;
            }

            $localPath = $localDb.$suffix;
            $this->downloadRemoteFile($filesystem, $remotePath, $localPath);
            $downloadedCompanions[] = $suffix;
        }

        $host = $this->getSshHost();
        $context = ['host' => $host, 'path' => $sourcePath];

        if (! empty($downloadedCompanions)) {
            $context['wal_files'] = $downloadedCompanions;
            $context['best_effort'] = true;
        }

        $logLevel = empty($downloadedCompanions) ? 'success' : 'warning';
        $logMessage = empty($downloadedCompanions)
            ? 'Downloaded SQLite database via SFTP'
            : 'Downloaded SQLite database via SFTP (best-effort: WAL files were downloaded sequentially and may not be consistent)';

        // Use sqlite3 .backup to consolidate the database (and any WAL data) into the output file
        return new DatabaseOperationResult(
            command: sprintf(
                'sqlite3 -readonly %s %s',
                escapeshellarg($localDb),
                escapeshellarg('.backup '.$outputPath),
            ),
            log: new DatabaseOperationLog(
                $logMessage,
                $logLevel,
                $context,
            ),
        );
    }

    /**
     * Download a single file from the SFTP filesystem to a local path.
     */
    private function downloadRemoteFile(Filesystem $filesystem, string $remotePath, string $localPath): void
    {
        $source = $filesystem->readStream($remotePath);

        $dest = fopen($localPath, 'wb');
        if ($dest === false) {
            fclose($source);
            throw new DatabaseDumpException("Failed to open destination for writing: {$localPath}");
        }

        try {
            $bytes = stream_copy_to_stream($source, $dest);
            if ($bytes === false || $bytes === 0) {
                throw new DatabaseDumpException("Failed to copy remote SQLite file {$remotePath} to {$localPath}");
            }
        } finally {
            fclose($source);
            fclose($dest);
        }
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $filesystem = $this->getSftpFilesystem();

        if ($filesystem !== null) {
            $stream = fopen($inputPath, 'rb');
            if ($stream === false) {
                throw new RestoreException("Failed to open file for reading: {$inputPath}");
            }
            try {
                $filesystem->writeStream($this->config['sqlite_path'], $stream);
            } finally {
                fclose($stream);
            }

            return new DatabaseOperationResult(log: new DatabaseOperationLog(
                'Uploaded SQLite database via SFTP',
                'success',
                ['host' => $this->getSshHost(), 'path' => $this->config['sqlite_path']],
            ));
        }

        $targetPath = $this->config['sqlite_path'];
        if (! @copy($inputPath, $targetPath)) {
            throw new RestoreException("Failed to copy SQLite file {$inputPath} to {$targetPath}");
        }
        chmod($targetPath, 0640);

        return new DatabaseOperationResult(log: new DatabaseOperationLog(
            'Restored local SQLite database',
            'success',
            ['path' => $this->config['sqlite_path']],
        ));
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger): void
    {
        // SQLite doesn't need database preparation — the file is replaced during restore
    }

    public function testConnection(): array
    {
        $paths = $this->config['sqlite_paths'] ?? [];

        // Fallback for single-path callers (dump/restore use sqlite_path)
        if (empty($paths) && ! empty($this->config['sqlite_path'])) {
            $paths = [$this->config['sqlite_path']];
        }

        if (empty($paths)) {
            return ['success' => false, 'message' => 'Database file path is required.', 'details' => []];
        }

        $filesystem = $this->getSftpFilesystem();
        if ($filesystem !== null) {
            return $this->testRemotePaths($paths, $filesystem, $this->getSshHost());
        }

        return $this->testLocalPaths($paths);
    }

    /**
     * Get an SFTP filesystem if SSH config is available (model or array).
     */
    private function getSftpFilesystem(): ?Filesystem
    {
        $sshConfig = $this->config['ssh_config'] ?? null;
        $sshConfigArray = $this->config['ssh_config_array'] ?? null;

        if ($sshConfig instanceof DatabaseServerSshConfig) {
            return $this->sftpFilesystem->getFromSshConfig($sshConfig);
        }

        if (is_array($sshConfigArray)) {
            return $this->sftpFilesystem->getFromDecryptedConfig($sshConfigArray);
        }

        return null;
    }

    /**
     * Get the SSH host for logging purposes.
     */
    private function getSshHost(): string
    {
        $sshConfig = $this->config['ssh_config'] ?? null;
        $sshConfigArray = $this->config['ssh_config_array'] ?? null;

        if ($sshConfig instanceof DatabaseServerSshConfig) {
            return $sshConfig->host;
        }

        if (is_array($sshConfigArray)) {
            return $sshConfigArray['host'] ?? 'unknown';
        }

        return 'unknown';
    }

    /**
     * Test all local SQLite database paths.
     *
     * @param  array<string>  $paths
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private function testLocalPaths(array $paths): array
    {
        $failures = [];
        $outputEntries = [];

        foreach ($paths as $path) {
            $result = $this->testSingleLocalPath($path);

            if (! $result['success']) {
                $failures[] = $result['message'];
            } elseif (! empty($result['details']['output'])) {
                $decoded = json_decode($result['details']['output'], true);
                if ($decoded !== null) {
                    $outputEntries[] = $decoded;
                }
            }
        }

        if (! empty($failures)) {
            return [
                'success' => false,
                'message' => implode("\n", $failures),
                'details' => [],
            ];
        }

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'output' => json_encode($outputEntries, JSON_PRETTY_PRINT),
            ],
        ];
    }

    /**
     * Test a single local SQLite file via PDO.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private function testSingleLocalPath(string $path): array
    {
        if (empty($path)) {
            return ['success' => false, 'message' => 'Database path is required.', 'details' => []];
        }

        if (! file_exists($path)) {
            return ['success' => false, 'message' => 'Database file does not exist: '.$path, 'details' => []];
        }

        if (! is_readable($path)) {
            return ['success' => false, 'message' => 'Database file is not readable: '.$path, 'details' => []];
        }

        if (! is_file($path)) {
            return ['success' => false, 'message' => 'Path is not a file: '.$path, 'details' => []];
        }

        try {
            $pdo = new \PDO("sqlite:{$path}", null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->query('SELECT 1 FROM sqlite_master LIMIT 1');

            $stmt = $pdo->query('SELECT sqlite_version()');
            $version = $stmt ? $stmt->fetchColumn() : 'unknown';

            $fileSize = filesize($path);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'output' => json_encode(['dbms' => "SQLite {$version}", 'file_size' => $fileSize, 'path' => $path], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => "Invalid SQLite database file ({$path}): ".$e->getMessage(), 'details' => []];
        }
    }

    /**
     * Test remote SQLite paths via SFTP: verify all files exist on remote server.
     *
     * @param  array<string>  $paths
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private function testRemotePaths(array $paths, Filesystem $filesystem, string $sshHost): array
    {
        try {
            $failures = [];
            $outputEntries = [];

            foreach ($paths as $remotePath) {
                if (! $filesystem->fileExists($remotePath)) {
                    $failures[] = $remotePath;

                    continue;
                }

                $fileSize = $filesystem->fileSize($remotePath);
                $outputEntries[] = [
                    'dbms' => 'SQLite (remote)',
                    'file_size' => $fileSize,
                    'path' => $remotePath,
                    'access' => 'SFTP via '.$sshHost,
                ];
            }

            if (! empty($failures)) {
                $pathList = implode(', ', $failures);

                return [
                    'success' => false,
                    'message' => count($failures) === 1
                        ? 'Remote file does not exist: '.$pathList
                        : 'Remote files do not exist: '.$pathList,
                    'details' => [],
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'sftp' => true,
                    'ssh_host' => $sshHost,
                    'output' => json_encode($outputEntries, JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'SFTP connection failed: '.$e->getMessage(), 'details' => []];
        }
    }
}
