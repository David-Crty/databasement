<?php

namespace App\Enums;

use App\Models\DatabaseServer;

enum DatabaseType: string
{
    case MYSQL = 'mysql';
    case POSTGRESQL = 'postgres';
    case SQLITE = 'sqlite';
    case REDIS = 'redis';
    case MONGODB = 'mongodb';
    case MSSQL = 'mssql';
    case FIREBIRD = 'firebird';

    public function label(): string
    {
        return match ($this) {
            self::MYSQL => 'MySQL / MariaDB',
            self::POSTGRESQL => 'PostgreSQL',
            self::SQLITE => 'SQLite',
            self::REDIS => 'Redis / Valkey',
            self::MONGODB => 'MongoDB',
            self::MSSQL => 'Microsoft SQL Server',
            self::FIREBIRD => 'Firebird',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::MYSQL => 'devicon.mysql',
            self::POSTGRESQL => 'devicon.postgresql',
            self::SQLITE => 'devicon.sqlite',
            self::REDIS => 'devicon.redis',
            self::MONGODB => 'devicon.mongodb',
            self::MSSQL => 'devicon.microsoftsqlserver',
            self::FIREBIRD => 'devicon.firebird',
        };
    }

    /**
     * Whether this type identifies databases by file path rather than by name.
     * SQLite stores everything in a local file; Firebird is networked but each
     * `.fdb` file on the server is its own database. Either way, the UI
     * collects file paths (not names) and there's no way to enumerate or
     * pattern-match — selection mode is always Selected.
     *
     * Orthogonal to whether the type uses host/port/credentials: that's still
     * determined elsewhere (SQLite has none, Firebird has all of them).
     */
    public function identifiesDatabasesByPath(): bool
    {
        return match ($this) {
            self::SQLITE, self::FIREBIRD => true,
            default => false,
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::MYSQL => 3306,
            self::POSTGRESQL => 5432,
            self::SQLITE => 0,
            self::REDIS => 6379,
            self::MONGODB => 27017,
            self::MSSQL => 1433,
            self::FIREBIRD => 3050,
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
        // MySQL PDO treats 'localhost' as a Unix socket connection.
        // Force TCP by using 127.0.0.1 instead.
        if ($this === self::MYSQL && $host === 'localhost') {
            $host = '127.0.0.1';
        }

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
            self::REDIS => throw new \RuntimeException('Redis does not support PDO connections'),
            self::MONGODB => throw new \RuntimeException('MongoDB does not support PDO connections'),
            self::MSSQL => $database
                ? sprintf('sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=true;Encrypt=true', $host, $port, $database)
                : sprintf('sqlsrv:Server=%s,%d;TrustServerCertificate=true;Encrypt=true', $host, $port),
            self::FIREBIRD => throw new \RuntimeException('Firebird does not support PDO connections'),
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
        if (in_array($this, [self::REDIS, self::MONGODB, self::FIREBIRD], true)) {
            throw new \RuntimeException("{$this->label()} does not support PDO connections");
        }

        $host = $server->host;
        if ($this === self::SQLITE) {
            $paths = $server->resolveDatabaseNames();
            if ($database !== null && trim($database) !== '') {
                $host = $database;
            } elseif (! empty($paths)) {
                $host = $paths[0];
            } else {
                throw new \InvalidArgumentException('SQLite database server requires at least one file path');
            }
            $database = null;
        }

        $dsn = $this->buildDsn($host, $server->port, $database);

        // sqlsrv encodes the connection timeout in the DSN itself (LoginTimeout)
        // and rejects PDO::ATTR_TIMEOUT as an unsupported attribute.
        if ($this === self::MSSQL) {
            $dsn .= ';LoginTimeout='.$timeout;
            $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
        } else {
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => $timeout,
            ];
        }

        return new \PDO($dsn, $server->username, $server->getDecryptedPassword(), $options);
    }

    /**
     * Validation rules for a destination database name/path of this type.
     *
     * SQLite accepts any non-empty path. Firebird is a path too but with a
     * restricted character set. All other types use the conservative
     * identifier charset (letters, numbers, underscores).
     *
     * @return array<int, string>
     */
    public function databaseNameRules(): array
    {
        return match ($this) {
            self::SQLITE => ['required', 'string', 'max:255'],
            self::FIREBIRD => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\/\\\\.\-: ]+$/'],
            default => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/'],
        };
    }

    /**
     * Validation messages for {@see databaseNameRules()}, keyed by `{field}.{rule}`.
     *
     * @return array<string, string>
     */
    public function databaseNameMessages(string $field): array
    {
        return match ($this) {
            self::SQLITE => [
                "{$field}.required" => __('Please enter a database path.'),
            ],
            self::FIREBIRD => [
                "{$field}.required" => __('Please enter a database name or path.'),
                "{$field}.regex" => __('Database name can only contain letters, numbers, spaces, slashes, dots, dashes, colons, and underscores.'),
            ],
            default => [
                "{$field}.required" => __('Please enter a database name.'),
                "{$field}.regex" => __('Database name can only contain letters, numbers, and underscores.'),
            ],
        };
    }

    /**
     * Get the file extension used for database dumps.
     *
     * $format is the postgres dump format ('plain'|'custom'); ignored for other types.
     */
    public function dumpExtension(?string $format = null): string
    {
        if ($this === self::POSTGRESQL && $format === 'custom') {
            return 'dump';
        }

        return match ($this) {
            self::SQLITE => 'db',
            self::REDIS => 'rdb',
            self::MONGODB => 'archive',
            self::MSSQL => 'dacpac',
            self::FIREBIRD => 'fbk',
            default => 'sql',
        };
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
