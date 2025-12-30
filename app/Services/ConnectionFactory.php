<?php

namespace App\Services;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\ConnectionException;
use App\Models\DatabaseServer;
use PDO;
use PDOException;

class ConnectionFactory
{
    /**
     * Create a PDO connection for administrative tasks (without specific database)
     */
    public function createAdminConnection(DatabaseServer $server, int $timeout = 30): PDO
    {
        $databaseType = DatabaseType::from($server->database_type);
        $dsn = $databaseType->buildAdminDsn($server->host, $server->port);

        return $this->createConnection($dsn, $server->username, $server->getDecryptedPassword(), $timeout);
    }

    private function createConnection(string $dsn, string $username, string $password, int $timeout): PDO
    {
        try {
            return new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => $timeout,
                ]
            );
        } catch (PDOException $e) {
            throw new ConnectionException("Failed to establish database connection: {$e->getMessage()}", 0, $e);
        }
    }
}
