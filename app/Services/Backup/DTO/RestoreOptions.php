<?php

namespace App\Services\Backup\DTO;

/**
 * The options a restore runs with, in the shape stored on a restore and a
 * scheduled restore: empty values are left out.
 */
readonly class RestoreOptions
{
    public ?string $ownerUser;

    /**
     * @param  list<string>  $restoreSchemas  Custom-format: schemas to keep in the filtered `--use-list`; empty = all
     * @param  list<array{schema: string, table: string}>  $restoreTables  Custom-format: tables to keep (schema-level objects still follow schemas); empty = all tables
     * @param  list<array{schema: string, table: string}>  $excludeRestoreTables  Custom-format: tables to comment out of the `--use-list`; empty = none
     */
    public function __construct(
        public bool $forceDatabase = false,
        ?string $ownerUser = null,
        public bool $parallelRestore = false,
        public array $restoreSchemas = [],
        public array $restoreTables = [],
        public array $excludeRestoreTables = [],
    ) {
        $ownerUser = trim((string) $ownerUser);
        $this->ownerUser = $ownerUser !== '' ? $ownerUser : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $ownerUser = $options['owner_user'] ?? null;

        return new self(
            forceDatabase: filter_var($options['force_database'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ownerUser: is_string($ownerUser) ? $ownerUser : null,
            parallelRestore: filter_var($options['parallel_restore'] ?? false, FILTER_VALIDATE_BOOLEAN),
            restoreSchemas: self::normalizeSchemas($options['restore_schemas'] ?? []),
            restoreTables: self::normalizeTables($options['restore_tables'] ?? []),
            excludeRestoreTables: self::normalizeTables($options['exclude_restore_tables'] ?? []),
        );
    }

    /**
     * Parse schema names from a comma / newline separated textarea.
     *
     * @return list<string>
     */
    public static function parseSchemaNames(string $text): array
    {
        $schemas = [];

        foreach (preg_split('/[,\r\n]+/', $text) ?: [] as $token) {
            $token = trim($token);

            if ($token !== '') {
                $schemas[] = $token;
            }
        }

        return array_values(array_unique($schemas));
    }

    /**
     * Parse `schema.table` names from a comma / newline separated textarea.
     *
     * @return list<array{schema: string, table: string}>
     */
    public static function parseQualifiedTables(string $text): array
    {
        $tables = [];

        foreach (preg_split('/[,\r\n]+/', $text) ?: [] as $token) {
            $token = trim($token);

            if ($token === '' || ! str_contains($token, '.')) {
                continue;
            }

            [$schema, $table] = explode('.', $token, 2);
            $schema = trim($schema);
            $table = trim($table);

            if ($schema !== '' && $table !== '') {
                $tables[] = ['schema' => $schema, 'table' => $table];
            }
        }

        return array_values(array_unique($tables, SORT_REGULAR));
    }

    /**
     * @return array{
     *     force_database?: true,
     *     owner_user?: string,
     *     parallel_restore?: true,
     *     restore_schemas?: list<string>,
     *     restore_tables?: list<array{schema: string, table: string}>,
     *     exclude_restore_tables?: list<array{schema: string, table: string}>,
     * }
     */
    public function toArray(): array
    {
        return array_filter([
            'force_database' => $this->forceDatabase ?: null,
            'owner_user' => $this->ownerUser,
            'parallel_restore' => $this->parallelRestore ?: null,
            'restore_schemas' => $this->restoreSchemas !== [] ? $this->restoreSchemas : null,
            'restore_tables' => $this->restoreTables !== [] ? $this->restoreTables : null,
            'exclude_restore_tables' => $this->excludeRestoreTables !== [] ? $this->excludeRestoreTables : null,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function normalizeSchemas(mixed $schemas): array
    {
        if (! is_array($schemas)) {
            return [];
        }

        $normalized = [];

        foreach ($schemas as $schema) {
            if (is_string($schema) && $schema !== '') {
                $normalized[] = $schema;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<array{schema: string, table: string}>
     */
    private static function normalizeTables(mixed $tables): array
    {
        if (is_string($tables)) {
            return self::parseQualifiedTables($tables);
        }

        if (! is_array($tables)) {
            return [];
        }

        $normalized = [];

        foreach ($tables as $table) {
            if (! is_array($table)) {
                continue;
            }

            $schema = $table['schema'] ?? null;
            $name = $table['table'] ?? null;

            if (is_string($schema) && $schema !== '' && is_string($name) && $name !== '') {
                $normalized[] = ['schema' => $schema, 'table' => $name];
            }
        }

        return array_values(array_unique($normalized, SORT_REGULAR));
    }
}
