<?php

namespace App\Enums;

enum DatabaseType: string
{
    case MYSQL = 'mysql';
    case POSTGRESQL = 'postgres';
    case SQLITE = 'sqlite';
    case MSSQL = 'sqlserver';

    public function label(): string
    {
        return match ($this) {
            self::MYSQL => 'MySQL / MariaDB',
            self::POSTGRESQL => 'PostgreSQL',
            self::SQLITE => 'SQLite',
            self::MSSQL => 'Microsoft SQL Server',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::MYSQL => 3306,
            self::POSTGRESQL => 5432,
            self::SQLITE => 0,
            self::MSSQL => 1433,
        };
    }

    /**
     * Build PDO DSN string for database connections.
     *
     * @param  string  $host  Hostname or file path (for SQLite)
     * @param  int  $port  Port number (ignored for SQLite)
     * @param  string|null  $database  Database name (null for admin connections)
     * @param  int  $timeout  Connection timeout in seconds (used in DSN for MSSQL since PDO::ATTR_TIMEOUT is not supported)
     */
    private function buildDsn(string $host, int $port, ?string $database = null, int $timeout = 30): string
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
            self::MSSQL => $database
                ? sprintf('sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=true;LoginTimeout=%d', $host, $port, $database, $timeout)
                : sprintf('sqlsrv:Server=%s,%d;TrustServerCertificate=true;LoginTimeout=%d', $host, $port, $timeout),
        };
    }

    /**
     * Create a PDO connection for this database type.
     *
     * @param  string  $host  Hostname or file path (for SQLite)
     * @param  int  $port  Port number (ignored for SQLite)
     * @param  string  $username  Database username
     * @param  string  $password  Database password
     * @param  string|null  $database  Database name (null for admin connections)
     * @param  int  $timeout  Connection timeout in seconds
     */
    public function createPdo(string $host, int $port, string $username, string $password, ?string $database = null, int $timeout = 30): \PDO
    {
        $dsn = $this->buildDsn($host, $port, $database, $timeout);

        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

        // PDO::ATTR_TIMEOUT is not supported by pdo_sqlsrv - timeout is set in DSN instead
        if ($this !== self::MSSQL) {
            $options[\PDO::ATTR_TIMEOUT] = $timeout;
        }

        return new \PDO($dsn, $username, $password, $options);
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

    /**
     * Create DatabaseType from various string formats.
     *
     * Supports both enum values (mysql, postgres, sqlserver, sqlite)
     * and common aliases (mssql).
     */
    public static function fromString(string $type): self
    {
        return match ($type) {
            'mysql' => self::MYSQL,
            'postgres' => self::POSTGRESQL,
            'sqlserver' => self::MSSQL,
            'mssql' => self::MSSQL,
            'sqlite' => self::SQLITE,
            default => self::from($type), // Will throw ValueError if invalid
        };
    }
}
