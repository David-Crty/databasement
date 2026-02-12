<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\SshTunnelService;
use PDO;
use PDOException;

class DatabaseListService
{
    private const EXCLUDED_MYSQL_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
    ];

    private const EXCLUDED_POSTGRESQL_DATABASES = [
        'postgres',          // Default administrative database
        'rdsadmin',          // AWS RDS internal database
        'azure_maintenance', // Azure Database for PostgreSQL internal database
        'azure_sys',         // Azure Database for PostgreSQL internal database
    ];

    private ?SshTunnelService $sshTunnelService = null;

    /**
     * Get list of databases/schemas from a database server
     *
     * @return array<string>
     */
    public function listDatabases(DatabaseServer $databaseServer): array
    {
        $tunnelEndpoint = null;

        // Redis dumps the entire instance - no individual databases to list
        if ($databaseServer->database_type === DatabaseType::REDIS) {
            return ['all'];
        }

        try {
            // Establish SSH tunnel if required
            if ($databaseServer->requiresSshTunnel()) {
                $this->sshTunnelService ??= new SshTunnelService;
                $tunnelEndpoint = $this->sshTunnelService->establish($databaseServer);
            }

            $pdo = $this->createConnection($databaseServer, $tunnelEndpoint);

            return match ($databaseServer->database_type) {
                DatabaseType::MYSQL => $this->listMysqlDatabases($pdo),
                DatabaseType::POSTGRESQL => $this->listPostgresqlDatabases($pdo),
                default => throw new \Exception("Database type {$databaseServer->database_type->value} not supported"),
            };
        } catch (PDOException $e) {
            throw new \Exception("Failed to list databases: {$e->getMessage()}", 0, $e);
        } finally {
            // Always close SSH tunnel service - close() cleans up temp files even if tunnel isn't active
            if ($this->sshTunnelService !== null) {
                $this->sshTunnelService->close();
            }
        }
    }

    /**
     * @return array<string>
     */
    private function listMysqlDatabases(PDO $pdo): array
    {
        $statement = $pdo->query('SHOW DATABASES');
        if ($statement === false) {
            throw new \RuntimeException('Failed to execute query: SHOW DATABASES');
        }
        $databases = $statement->fetchAll(PDO::FETCH_COLUMN, 0);

        // Filter out system databases
        return array_values(array_filter($databases, function ($db) {
            return ! in_array($db, self::EXCLUDED_MYSQL_DATABASES);
        }));
    }

    /**
     * @return array<string>
     */
    private function listPostgresqlDatabases(PDO $pdo): array
    {
        $statement = $pdo->query(
            'SELECT datname FROM pg_database WHERE datistemplate = false'
        );
        if ($statement === false) {
            throw new \RuntimeException('Failed to execute query: SELECT datname FROM pg_database');
        }

        $databases = $statement->fetchAll(PDO::FETCH_COLUMN, 0);

        return array_values(array_filter($databases, function ($db) {
            return ! in_array($db, self::EXCLUDED_POSTGRESQL_DATABASES);
        }));
    }

    /**
     * Create a PDO connection, using tunnel endpoint if provided.
     *
     * @param  array{host: string, port: int}|null  $tunnelEndpoint
     */
    protected function createConnection(DatabaseServer $databaseServer, ?array $tunnelEndpoint = null): PDO
    {
        if ($tunnelEndpoint !== null) {
            $host = $tunnelEndpoint['host'];
            $port = $tunnelEndpoint['port'];

            $dsn = match ($databaseServer->database_type) {
                DatabaseType::MYSQL => sprintf('mysql:host=%s;port=%d', $host, $port),
                DatabaseType::POSTGRESQL => sprintf('pgsql:host=%s;port=%d;dbname=postgres', $host, $port),
                default => throw new \Exception("Database type {$databaseServer->database_type->value} not supported"),
            };

            return new PDO($dsn, $databaseServer->username, $databaseServer->getDecryptedPassword(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        }

        return $databaseServer->database_type->createPdo($databaseServer, null, 5);
    }
}
