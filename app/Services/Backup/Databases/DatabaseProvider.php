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
            DatabaseType::FIREBIRD => new FirebirdDatabase,
            DatabaseType::S3 => new S3Database,
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
     * $snapshotDumpFormat and $snapshotDumpPrivileges override the target's
     * extra_config at restore time: both are properties of the snapshot file,
     * not the destination server.
     */
    public function makeFromConfig(
        DatabaseConnectionConfig $config,
        string $databaseName,
        string $host,
        int $port,
        ?string $sourceDatabaseName = null,
        ?string $snapshotDumpFormat = null,
        ?bool $snapshotDumpPrivileges = null,
    ): DatabaseInterface {
        if ($config->databaseType === DatabaseType::SQLITE) {
            return $this->makeConfigured(DatabaseType::SQLITE, $this->sqliteConfig($databaseName, $config->sshConfig));
        }

        $extra = $config->extraConfig ?? [];
        $dbConfig = $this->connectionConfig($config, $databaseName, $host, $port);
        $dbConfig = $this->applyMongoConfig($dbConfig, $config->databaseType, $extra, $sourceDatabaseName);

        if (! empty($extra['dump_flags'])) {
            $dbConfig['dump_flags'] = $extra['dump_flags'];
        }

        if (in_array($config->databaseType, [DatabaseType::MYSQL, DatabaseType::POSTGRESQL], true) && ! empty($extra['ssl_enabled'])) {
            $dbConfig['ssl_enabled'] = true;
        }

        // Marks a live server: the handler may query it for the MySQL/MariaDB
        // flavour, or for the PostgreSQL major that decides which client build
        // to run. Display-only configs, like the dump preview, leave it unset.
        if (in_array($config->databaseType, [DatabaseType::MYSQL, DatabaseType::POSTGRESQL], true)) {
            $dbConfig['probe_server_version'] = true;
        }

        if ($config->databaseType === DatabaseType::POSTGRESQL) {
            $dbConfig['connection_database'] = self::connectionDatabase($extra);
        }

        if ($config->databaseType === DatabaseType::POSTGRESQL
            && ($snapshotDumpFormat ?? $extra['dump_format'] ?? null) === 'custom') {
            $dbConfig['dump_format'] = 'custom';
        }

        if ($config->databaseType === DatabaseType::POSTGRESQL
            && ($snapshotDumpPrivileges ?? ! empty($extra['dump_privileges']))) {
            $dbConfig['dump_privileges'] = true;
        }

        // Optional short timeout used by interactive UI lookups; jobs leave
        // it unset and fall back to each handler's longer default.
        if (isset($extra['connect_timeout'])) {
            $dbConfig['connect_timeout'] = (int) $extra['connect_timeout'];
        }

        return $this->makeConfigured($config->databaseType, $dbConfig);
    }

    /**
     * @param  array<string, mixed>|null  $sshConfig
     * @return array<string, mixed>
     */
    private function sqliteConfig(string $databaseName, ?array $sshConfig): array
    {
        $dbConfig = ['sqlite_path' => $databaseName];

        if ($sshConfig !== null) {
            $dbConfig['ssh_config_array'] = $sshConfig;
        }

        return $dbConfig;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(
        DatabaseConnectionConfig $config,
        string $databaseName,
        string $host,
        int $port,
    ): array {
        if ($config->databaseType === DatabaseType::S3) {
            return $this->objectStorageConfig($config, $host, $port);
        }

        $dbConfig = [
            'host' => $host,
            'port' => $port,
            'user' => $config->username,
            'pass' => $config->password,
        ];

        if ($config->databaseType === DatabaseType::REDIS) {
            return $dbConfig;
        }

        $dbConfig['database'] = $databaseName;

        return $dbConfig;
    }

    /**
     * Build the Flysystem-compatible config for an S3-compatible object
     * storage server. The "database server" points at one bucket and its
     * folders are the per-unit source paths. The schema intentionally mirrors
     * the Volume S3 config ({@see \App\Livewire\Volume\Connectors\S3Config}) so
     * the same {@see \App\Services\Backup\Filesystems\Awss3Filesystem} adapter
     * and bucket engines consume either.
     *
     * @param  array<string, mixed>|null  $extraConfig
     * @return array<string, mixed>
     */
    public static function objectStorageConfig(
        DatabaseConnectionConfig $config,
        ?string $host = null,
        ?int $port = null,
        ?array $extraConfig = null,
    ): array {
        $extra = $extraConfig ?? $config->extraConfig ?? [];
        $host = $host ?? $config->host;
        $port = $port ?? $config->port;
        $scheme = ! empty($extra['ssl_enabled']) ? 'https' : 'http';

        // Accept an already-absolute URI in `host` (some setups pre-bake the
        // scheme), otherwise qualify the plain host with the scheme.
        $isAbsoluteUri = str_contains($host, '://');
        $endpoint = $isAbsoluteUri ? $host : $scheme.'://'.$host;

        // Only append a scheme-unqualified host's port, and never duplicate a
        // port the caller already baked into `host`.
        $authoritySuffix = ':'.$port;
        if ($port > 0 && ! $isAbsoluteUri && ! str_contains($host, $authoritySuffix)) {
            $endpoint .= $authoritySuffix;
        }

        return [
            'region' => (string) ($extra['s3_region'] ?? 'us-east-1'),
            'bucket' => (string) ($extra['s3_bucket'] ?? ''),
            'access_key_id' => $config->username,
            'secret_access_key' => $config->password,
            'custom_endpoint' => $endpoint,
            'use_path_style_endpoint' => ! empty($extra['s3_use_path_style_endpoint']),
            'root' => (string) ($extra['s3_prefix'] ?? ''),
            // Rich detail used by UI / logging.
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * @param  array<string, mixed>  $dbConfig
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function applyMongoConfig(
        array $dbConfig,
        DatabaseType $databaseType,
        array $extra,
        ?string $sourceDatabaseName,
    ): array {
        if ($databaseType !== DatabaseType::MONGODB) {
            return $dbConfig;
        }

        $dbConfig['auth_source'] = $extra['auth_source'] ?? 'admin';
        $dbConfig['srv'] = ! empty($extra['srv_enabled']);
        $dbConfig['connection_options'] = $extra['connection_options'] ?? '';
        if ($sourceDatabaseName !== null) {
            $dbConfig['source_database'] = $sourceDatabaseName;
        }

        return $dbConfig;
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
        // SQLite identifies databases by configured file path. Connecting just
        // to read back a basename loses the path; return the configured paths
        // directly so callers (restore autocomplete, agent discovery) see them.
        if ($server->database_type === DatabaseType::SQLITE) {
            return $server->resolveDatabaseNames();
        }

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
            DatabaseType::POSTGRESQL => self::connectionDatabase($server->extra_config ?? []),
            DatabaseType::MSSQL => 'master',
            DatabaseType::FIREBIRD => $server->resolveDatabaseNames()[0] ?? '',
            default => '',
        };
    }

    /**
     * PostgreSQL refuses a connection that names no database, so testing a
     * connection and listing the catalogue both have to open one. `postgres` is
     * the conventional choice, but managed providers (Heroku, RDS, Neon, …)
     * routinely withhold CONNECT on it, so a server can name another database
     * through extra_config.connection_database.
     *
     * @param  array<string, mixed>  $extraConfig
     */
    public static function connectionDatabase(array $extraConfig): string
    {
        return PostgresqlDatabase::resolveConnectionDatabase($extraConfig);
    }
}
