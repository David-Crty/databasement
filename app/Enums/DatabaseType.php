<?php

namespace App\Enums;

enum DatabaseType: string
{
    case MYSQL = 'mysql';
    case MARIADB = 'mariadb';
    case POSTGRESQL = 'postgresql';
    case SQLITE = 'sqlite';

    public function isMysqlFamily(): bool
    {
        return in_array($this, [self::MYSQL, self::MARIADB]);
    }

    public function label(): string
    {
        return match ($this) {
            self::MYSQL => 'MySQL',
            self::MARIADB => 'MariaDB',
            self::POSTGRESQL => 'PostgreSQL',
            self::SQLITE => 'SQLite',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 3306,
            self::POSTGRESQL => 5432,
            self::SQLITE => 0,
        };
    }

    public function buildDsn(string $host, int $port, ?string $database = null): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => sprintf(
                'mysql:host=%s;port=%d%s;charset=utf8mb4',
                $host,
                $port,
                $database ? ";dbname={$database}" : ''
            ),
            self::POSTGRESQL => sprintf(
                'pgsql:host=%s;port=%d%s',
                $host,
                $port,
                $database ? ";dbname={$database}" : ''
            ),
            self::SQLITE => "sqlite:{$host}",
        };
    }

    /**
     * Build DSN for administrative connections (without specific database)
     */
    public function buildAdminDsn(string $host, int $port): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => sprintf(
                'mysql:host=%s;port=%d',
                $host,
                $port
            ),
            self::POSTGRESQL => sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $host,
                $port
            ),
            self::SQLITE => "sqlite:{$host}",
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
