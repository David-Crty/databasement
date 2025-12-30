<?php

namespace App\Services\Backup;

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

    private const EXCLUDED_POSTGRESQL_DATABASES = [];

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
                'mysql' => $this->listMysqlDatabases($pdo),
                'postgres' => $this->listPostgresqlDatabases($pdo),
                default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
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
        $dsn = $this->buildDsn($databaseServer);

        return new PDO(
            $dsn,
            $databaseServer->username,
            $databaseServer->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }

    protected function buildDsn(DatabaseServer $databaseServer): string
    {
        return match ($databaseServer->database_type) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d',
                $databaseServer->host,
                $databaseServer->port
            ),
            'postgres' => sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $databaseServer->host,
                $databaseServer->port
            ),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };
    }
}
