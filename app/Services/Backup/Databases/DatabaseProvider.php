<?php

namespace App\Services\Backup\Databases;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\Filesystems\SftpFilesystem;
use App\Services\SshTunnelService;

class DatabaseProvider
{
    public function __construct(
        private readonly SftpFilesystem $sftpFilesystem = new SftpFilesystem,
        private readonly SshTunnelService $sshTunnelService = new SshTunnelService,
    ) {}

    /**
     * Create a database interface instance for the given type.
     */
    public function make(DatabaseType $type): DatabaseInterface
    {
        return match ($type) {
            DatabaseType::MYSQL => new MysqlDatabase,
            DatabaseType::POSTGRESQL => new PostgresqlDatabase,
            DatabaseType::SQLITE => new SqliteDatabase($this->sftpFilesystem),
            DatabaseType::REDIS => new RedisDatabase,
        };
    }

    /**
     * Create a configured database interface instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function makeConfigured(DatabaseType $type, array $config): DatabaseInterface
    {
        $database = $this->make($type);
        $database->setConfig($config);

        return $database;
    }

    /**
     * Create a configured database interface from a server model.
     *
     * Host and port are passed explicitly to support SSH tunnel overrides.
     */
    public function makeForServer(DatabaseServer $server, string $databaseName, string $host, int $port): DatabaseInterface
    {
        if ($server->database_type === DatabaseType::SQLITE) {
            $sqlitePath = str_starts_with($databaseName, '/') ? $databaseName : $server->sqlite_path;
            $config = ['sqlite_path' => $sqlitePath];

            if ($server->sshConfig !== null) {
                $config['ssh_config'] = $server->sshConfig;
            }
        } else {
            $config = [
                'host' => $host,
                'port' => $port,
                'user' => $server->username,
                'pass' => $server->getDecryptedPassword(),
            ];

            if ($server->database_type !== DatabaseType::REDIS) {
                $config['database'] = $databaseName;
            }
        }

        return $this->makeConfigured($server->database_type, $config);
    }

    /**
     * Test a database connection, establishing an SSH tunnel first if configured.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnectionForServer(DatabaseServer $server): array
    {
        if ($server->requiresSftpTransfer()) {
            return $this->testSftp($server);
        }

        if ($server->requiresSshTunnel()) {
            $sshResult = $this->sshTunnelService->testConnection($server->sshConfig);
            if (! $sshResult['success']) {
                return ['success' => false, 'message' => 'SSH connection failed: '.$sshResult['message'], 'details' => []];
            }
        }

        try {
            [$host, $port] = $this->resolveHostAndPort($server);

            $database = $this->makeForServer($server, $this->getConnectionDatabaseName($server), $host, $port);
            $result = $database->testConnection();

            if ($result['success'] && $server->requiresSshTunnel()) {
                $result['details']['ssh_tunnel'] = true;
                $result['details']['ssh_host'] = $server->sshConfig->host;
            }

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection test failed: '.$e->getMessage(), 'details' => []];
        } finally {
            $this->sshTunnelService->close();
        }
    }

    /**
     * List databases for a server, handling SSH tunnel lifecycle.
     *
     * @return array<string>
     */
    public function listDatabasesForServer(DatabaseServer $server): array
    {
        try {
            [$host, $port] = $this->resolveHostAndPort($server);

            $database = $this->makeForServer($server, $this->getConnectionDatabaseName($server), $host, $port);

            return $database->listDatabases();
        } finally {
            $this->sshTunnelService->close();
        }
    }

    /**
     * Resolve host and port, establishing an SSH tunnel if needed.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveHostAndPort(DatabaseServer $server): array
    {
        if ($server->requiresSshTunnel()) {
            $tunnelEndpoint = $this->sshTunnelService->establish($server);

            return [$tunnelEndpoint['host'], $tunnelEndpoint['port']];
        }

        return [$server->host ?? '', $server->port];
    }

    /**
     * Get the database name to use for connection testing and listing.
     */
    private function getConnectionDatabaseName(DatabaseServer $server): string
    {
        return $server->database_type === DatabaseType::POSTGRESQL ? 'postgres' : '';
    }

    /**
     * Test remote SQLite connection via SFTP: verify file exists on remote server.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private function testSftp(DatabaseServer $server): array
    {
        $sshConfig = $server->sshConfig;
        if ($sshConfig === null) {
            return ['success' => false, 'message' => 'SSH configuration not found for this server.', 'details' => []];
        }

        $remotePath = $server->sqlite_path ?? '';
        if (empty($remotePath)) {
            return ['success' => false, 'message' => 'Database file path is required.', 'details' => []];
        }

        try {
            $filesystem = $this->sftpFilesystem->getFromSshConfig($sshConfig);

            if (! $filesystem->fileExists($remotePath)) {
                return ['success' => false, 'message' => 'Remote file does not exist: '.$remotePath, 'details' => []];
            }

            $fileSize = $filesystem->fileSize($remotePath);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'sftp' => true,
                    'ssh_host' => $sshConfig->host,
                    'output' => json_encode([
                        'dbms' => 'SQLite (remote)',
                        'file_size' => $fileSize,
                        'path' => $remotePath,
                        'access' => 'SFTP via '.$sshConfig->getDisplayName(),
                    ], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'SFTP connection failed: '.$e->getMessage(), 'details' => []];
        }
    }
}
