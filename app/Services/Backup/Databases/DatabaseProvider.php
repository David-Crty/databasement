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
     * List databases for a server, handling SSH tunnel lifecycle.
     *
     * @return array<string>
     */
    public function listDatabasesForServer(DatabaseServer $server): array
    {
        $tunnelEndpoint = null;

        try {
            if ($server->requiresSshTunnel()) {
                $tunnelEndpoint = $this->sshTunnelService->establish($server);
            }

            $host = $tunnelEndpoint['host'] ?? $server->host ?? '';
            $port = $tunnelEndpoint['port'] ?? $server->port;
            $databaseName = $server->database_type === DatabaseType::POSTGRESQL ? 'postgres' : '';

            $database = $this->makeForServer($server, $databaseName, $host, $port);

            return $database->listDatabases();
        } finally {
            $this->sshTunnelService->close();
        }
    }
}
