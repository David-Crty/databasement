<?php

namespace App\Enums;

use App\Models\DatabaseServer;

enum DatabaseType: string
{
    case MYSQL = 'mysql';
    case POSTGRESQL = 'postgres';
    case SQLITE = 'sqlite';

    public function label(): string
    {
        return match ($this) {
            self::MYSQL => 'MySQL / MariaDB',
            self::POSTGRESQL => 'PostgreSQL',
            self::SQLITE => 'SQLite',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::MYSQL => 3306,
            self::POSTGRESQL => 5432,
            self::SQLITE => 0,
        };
    }

    /**
     * Build PDO DSN string for database connections.
     *
     * @param  string  $host  Hostname or file path (for SQLite)
     * @param  int  $port  Port number (ignored for SQLite)
     * @param  string|null  $database  Database name (null for admin connections)
     */
    private function buildDsn(string $host, int $port, ?string $database = null): string
    {
        return match ($this) {
            self::MYSQL => $database
                ? sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database)
                : sprintf('mysql:host=%s;port=%d', $host, $port),
            self::POSTGRESQL => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $database ?? 'postgres'
            ),
            self::SQLITE => "sqlite:{$host}",
        };
    }

    /**
     * Create a PDO connection for this database type.
     *
     * @param  DatabaseServer  $server  The database server to connect to
     * @param  string|null  $database  Database name (null for admin connections)
     * @param  int  $timeout  Connection timeout in seconds
     */
    public function createPdo(DatabaseServer $server, ?string $database = null, int $timeout = 30): \PDO
    {
        $host = $server->host;
        if ($this === self::SQLITE) {
            if (empty($server->sqlite_path)) {
                throw new \InvalidArgumentException('SQLite database server requires sqlite_path to be set');
            }
            $host = $server->sqlite_path;
        }

        $dsn = $this->buildDsn($host, $server->port, $database);
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $timeout,
        ];

        return new \PDO($dsn, $server->username, $server->getDecryptedPassword(), $options);
    }

    /**
     * @return array<array{id: string, name: string}>
     */
    public static function toSelectOptions(): array
    {
        return array_map(
            fn (self $type) => ['id' => $type->value, 'name' => $type->label()],
            self::cases()
        );
    }
}
