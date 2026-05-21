<?php

namespace App\Services\Backup\Databases;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
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
            DatabaseType::MONGODB => new MongodbDatabase,
            DatabaseType::MSSQL => new MssqlDatabase,
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
     * Delegates to makeFromConfig() for non-SQLite types; SQLite is kept inline
     * because its SSH config uses the Eloquent model directly, not the
     * decrypted array shape carried by DatabaseConnectionConfig.
     */
    public function makeForServer(
        DatabaseServer $server,
        string $databaseName,
        string $host,
        int $port,
        ?string $sourceDatabaseName = null,
    ): DatabaseInterface {
        if ($server->database_type === DatabaseType::SQLITE) {
            $config = ['sqlite_path' => $databaseName];

            if ($server->sshConfig !== null) {
                $config['ssh_config'] = $server->sshConfig;
            }

            return $this->makeConfigured(DatabaseType::SQLITE, $config);
        }

        return $this->makeFromConfig(
            DatabaseConnectionConfig::fromServer($server),
            $databaseName,
            $host,
            $port,
            $sourceDatabaseName,
        );
    }

    /**
     * Create a configured database interface from a DatabaseConnectionConfig DTO.
     *
     * Host and port are passed explicitly to support SSH tunnel overrides.
     * $snapshotDumpFormat overrides the target's dump_format at restore time: format
     * is a property of the snapshot file, not the destination server.
     */
    public function makeFromConfig(
        DatabaseConnectionConfig $config,
        string $databaseName,
        string $host,
        int $port,
        ?string $sourceDatabaseName = null,
        ?string $snapshotDumpFormat = null,
    ): DatabaseInterface {
        if ($config->databaseType === DatabaseType::SQLITE) {
            $dbConfig = ['sqlite_path' => $databaseName];

            if ($config->sshConfig !== null) {
                $dbConfig['ssh_config_array'] = $config->sshConfig;
            }

            return $this->makeConfigured(DatabaseType::SQLITE, $dbConfig);
        }

        $extra = $config->extraConfig ?? [];

        $dbConfig = [
            'host' => $host,
            'port' => $port,
            'user' => $config->username,
            'pass' => $config->password,
        ];

        if ($config->databaseType !== DatabaseType::REDIS) {
            $dbConfig['database'] = $databaseName;
        }

        if ($config->databaseType === DatabaseType::MONGODB) {
            $dbConfig['auth_source'] = $extra['auth_source'] ?? 'admin';
            if ($sourceDatabaseName !== null) {
                $dbConfig['source_database'] = $sourceDatabaseName;
            }
        }

        if (! empty($extra['dump_flags'])) {
            $dbConfig['dump_flags'] = $extra['dump_flags'];
        }

        if ($config->databaseType === DatabaseType::MYSQL && ! empty($extra['ssl_enabled'])) {
            $dbConfig['ssl_enabled'] = true;
        }

        if ($config->databaseType === DatabaseType::POSTGRESQL
            && ($snapshotDumpFormat ?? $extra['dump_format'] ?? null) === 'custom') {
            $dbConfig['dump_format'] = 'custom';
        }

        // Optional short timeout used by interactive UI lookups; jobs leave
        // it unset and fall back to each handler's longer default.
        if (isset($extra['connect_timeout'])) {
            $dbConfig['connect_timeout'] = (int) $extra['connect_timeout'];
        }

        return $this->makeConfigured($config->databaseType, $dbConfig);
    }

    /**
     * Test a database connection, establishing an SSH tunnel first if configured.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnectionForServer(DatabaseServer $server): array
    {
        if ($server->database_type === DatabaseType::SQLITE) {
            $config = ['sqlite_paths' => $server->resolveDatabaseNames()];
            if ($server->sshConfig !== null) {
                $config['ssh_config'] = $server->sshConfig;
            }

            $database = $this->makeConfigured(DatabaseType::SQLITE, $config);

            return $database->testConnection();
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
        if ($server->database_type === DatabaseType::SQLITE) {
            $paths = $server->resolveDatabaseNames();

            return $paths[0] ?? '';
        }

        return match ($server->database_type) {
            DatabaseType::POSTGRESQL => 'postgres',
            DatabaseType::MSSQL => 'master',
            default => '',
        };
    }
}
