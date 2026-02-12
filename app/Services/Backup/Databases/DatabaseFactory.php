<?php

namespace App\Services\Backup\Databases;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;

class DatabaseFactory
{
    /**
     * Create a database interface instance for the given type.
     */
    public function make(DatabaseType $type): DatabaseInterface
    {
        return match ($type) {
            DatabaseType::MYSQL => new MysqlDatabase,
            DatabaseType::POSTGRESQL => new PostgresqlDatabase,
            DatabaseType::SQLITE => new SqliteDatabase,
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
            $config = ['sqlite_path' => $server->sqlite_path];
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
}
