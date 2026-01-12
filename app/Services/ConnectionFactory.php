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
        try {
            return DatabaseType::from($server->database_type)->createPdo(
                $server->host,
                $server->port,
                $server->username,
                $server->getDecryptedPassword(),
                null,
                $timeout
            );
        } catch (PDOException $e) {
            throw new ConnectionException("Failed to establish database connection: {$e->getMessage()}", 0, $e);
        }
    }
}
