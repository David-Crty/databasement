<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
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

    /**
     * Get list of databases/schemas from a database server
     *
     * @return array<string>
     */
    public function listDatabases(DatabaseServer $databaseServer): array
    {
        try {
            $pdo = $this->createConnection($databaseServer);

            return match ($databaseServer->database_type) {
                DatabaseType::MYSQL => $this->listMysqlDatabases($pdo),
                DatabaseType::POSTGRESQL => $this->listPostgresqlDatabases($pdo),
                default => throw new \Exception("Database type {$databaseServer->database_type->value} not supported"),
            };
        } catch (PDOException $e) {
            throw new \Exception("Failed to list databases: {$e->getMessage()}", 0, $e);
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

    protected function createConnection(DatabaseServer $databaseServer): PDO
    {
        return $databaseServer->database_type->createPdo($databaseServer, null, 5);
    }
}
