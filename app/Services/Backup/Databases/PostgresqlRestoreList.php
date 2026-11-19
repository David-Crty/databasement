<?php

namespace App\Services\Backup\Databases;

/**
 * Edits a `pg_restore --list` TOC for selective restores.
 *
 * pg_restore's `--schema` / `--table` switches are a poor fit for this UI:
 *
 * - `-n` restores objects *in* a schema but not the `CREATE SCHEMA` itself
 * - `-t` restores a table but not its schema, indexes, or other dependents
 * - `-n` and `-t` together form an intersection, so the SCHEMA TOC line is dropped
 *
 * The documented approach is to take the archive's own TOC and comment out
 * unwanted lines with `;` — order and dump-ids stay intact.
 *
 * Selection semantics:
 *
 * - Schemas: keep every object in those schemas (functions, procedures, types,
 *   sequences, tables, constraints, …) plus the SCHEMA definition.
 * - Tables to restore: when set, only those tables' table-scoped objects are
 *   kept; schema-level objects (functions, procedures, …) still follow the
 *   selected schemas (or the schemas of the included tables).
 * - Tables to skip: comment out those tables' table-scoped objects.
 */
final class PostgresqlRestoreList
{
    /**
     * Multi-word TOC types, longest first so "SEQUENCE OWNED BY" wins over "SEQUENCE".
     *
     * @var list<string>
     */
    private const array MULTI_WORD_TYPES = [
        'SEQUENCE OWNED BY',
        'FK CONSTRAINT',
        'TABLE DATA',
        'SEQUENCE SET',
        'MATERIALIZED VIEW',
        'SQL FUNCTION',
        'ACCESS METHOD',
        'EVENT TRIGGER',
        'FOREIGN TABLE',
        'FOREIGN SERVER',
        'FOREIGN DATA',
        'ROW SECURITY',
        'POLICY',
    ];

    /**
     * Types whose name field is the table (second token after schema is often a sub-name).
     *
     * @var list<string>
     */
    private const array TABLE_SCOPED_TYPES = [
        'TABLE',
        'TABLE DATA',
        'VIEW',
        'MATERIALIZED VIEW',
        'FOREIGN TABLE',
        'CONSTRAINT',
        'FK CONSTRAINT',
        'DEFAULT',
        'TRIGGER',
        'POLICY',
        'ROW SECURITY',
        'RULE',
    ];

    /**
     * Schema-level objects that follow a selected schema even when tables are narrowed.
     *
     * @var list<string>
     */
    private const array SCHEMA_LEVEL_TYPES = [
        'FUNCTION',
        'SQL FUNCTION',
        'PROCEDURE',
        'AGGREGATE',
        'TYPE',
        'DOMAIN',
        'OPERATOR',
        'OPERATOR CLASS',
        'OPERATOR FAMILY',
        'COLLATION',
        'TEXT SEARCH PARSER',
        'TEXT SEARCH DICTIONARY',
        'TEXT SEARCH TEMPLATE',
        'TEXT SEARCH CONFIGURATION',
        'CAST',
        'TRANSFORM',
        'LANGUAGE',
        'STATISTICS',
    ];

    /**
     * @param  list<string>  $schemas
     * @param  list<array{schema: string, table: string}>  $includeTables
     * @param  list<array{schema: string, table: string}>  $excludeTables
     */
    public static function filter(
        string $toc,
        array $schemas = [],
        array $includeTables = [],
        array $excludeTables = [],
    ): string {
        $schemaSet = array_fill_keys($schemas, true);
        $includeSet = self::qualifiedSet($includeTables);
        $excludeSet = self::qualifiedSet($excludeTables);

        if ($includeSet !== [] && $schemaSet !== []) {
            $includeSet = array_filter(
                $includeSet,
                function (bool $_, string $qualified) use ($schemaSet): bool {
                    [$schema] = explode('.', $qualified, 2);

                    return isset($schemaSet[$schema]);
                },
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $includeSchemas = [];
        foreach (array_keys($includeSet) as $qualified) {
            [$schema] = explode('.', $qualified, 2);
            $includeSchemas[$schema] = true;
        }

        // Schema-level objects (functions, …) follow explicitly selected schemas,
        // or — when only tables were chosen — the schemas of those tables.
        $schemaLevelSchemas = $schemaSet !== [] ? $schemaSet : $includeSchemas;

        $lines = [];

        foreach (preg_split('/\R/', $toc) ?: [] as $line) {
            $trimmed = rtrim($line, "\r");

            if ($trimmed === '' || str_starts_with(ltrim($trimmed), ';')) {
                $lines[] = $trimmed;

                continue;
            }

            $parsed = self::parse($trimmed);
            $keep = self::shouldKeep($parsed, $schemaSet, $includeSet, $includeSchemas, $schemaLevelSchemas, $excludeSet);

            $lines[] = $keep ? $trimmed : ';'.$trimmed;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{type: string, schema: ?string, table: ?string, name: ?string}|null  $parsed
     * @param  array<string, true>  $schemaSet
     * @param  array<string, true>  $includeSet
     * @param  array<string, true>  $includeSchemas
     * @param  array<string, true>  $schemaLevelSchemas
     * @param  array<string, true>  $excludeSet
     */
    private static function shouldKeep(
        ?array $parsed,
        array $schemaSet,
        array $includeSet,
        array $includeSchemas,
        array $schemaLevelSchemas,
        array $excludeSet,
    ): bool {
        // Unrecognised / global (no schema): leave active so extensions etc. still restore.
        if ($parsed === null || $parsed['schema'] === null) {
            return true;
        }

        $schema = $parsed['schema'];
        $type = $parsed['type'];
        $qualifiedTable = $parsed['table'] !== null ? $schema.'.'.$parsed['table'] : null;

        if ($type === 'SCHEMA') {
            if ($includeSet !== []) {
                return isset($includeSchemas[$schema]) || isset($schemaSet[$schema]);
            }

            return $schemaSet === [] || isset($schemaSet[$schema]);
        }

        if ($excludeSet !== [] && self::isExcludedTableObject($parsed, $excludeSet)) {
            return false;
        }

        if ($includeSet !== []) {
            if (self::isTableScoped($type) && $qualifiedTable !== null) {
                return isset($includeSet[$qualifiedTable]);
            }

            if (self::isSequenceType($type)) {
                return self::sequenceMatchesTables($schema, (string) $parsed['name'], $includeSet);
            }

            if ($type === 'INDEX') {
                return self::indexMatchesTables($schema, (string) $parsed['name'], $includeSet);
            }

            if (self::isSchemaLevel($type)) {
                return isset($schemaLevelSchemas[$schema]);
            }

            // ACL / COMMENT / other: keep when the schema is in play.
            return isset($schemaLevelSchemas[$schema]) || isset($includeSchemas[$schema]);
        }

        if ($schemaSet !== []) {
            return isset($schemaSet[$schema]);
        }

        return true;
    }

    /**
     * @param  array{type: string, schema: ?string, table: ?string, name: ?string}  $parsed
     * @param  array<string, true>  $excludeSet
     */
    private static function isExcludedTableObject(array $parsed, array $excludeSet): bool
    {
        $schema = $parsed['schema'];
        if ($schema === null) {
            return false;
        }

        if ($parsed['table'] !== null && isset($excludeSet[$schema.'.'.$parsed['table']])) {
            return true;
        }

        if (self::isSequenceType($parsed['type']) && self::sequenceMatchesTables($schema, (string) $parsed['name'], $excludeSet)) {
            return true;
        }

        return $parsed['type'] === 'INDEX'
            && self::indexMatchesTables($schema, (string) $parsed['name'], $excludeSet);
    }

    private static function isTableScoped(string $type): bool
    {
        return in_array($type, self::TABLE_SCOPED_TYPES, true);
    }

    private static function isSchemaLevel(string $type): bool
    {
        return in_array($type, self::SCHEMA_LEVEL_TYPES, true);
    }

    private static function isSequenceType(string $type): bool
    {
        return in_array($type, ['SEQUENCE', 'SEQUENCE SET', 'SEQUENCE OWNED BY'], true);
    }

    /**
     * @param  array<string, true>  $tables
     */
    private static function sequenceMatchesTables(string $schema, string $sequence, array $tables): bool
    {
        foreach (array_keys($tables) as $qualified) {
            [$tableSchema, $table] = explode('.', $qualified, 2);
            if ($tableSchema === $schema && str_starts_with($sequence, $table.'_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indexes only carry the index name in the TOC (e.g. idx_users_org), not the table.
     *
     * @param  array<string, true>  $tables
     */
    private static function indexMatchesTables(string $schema, string $index, array $tables): bool
    {
        foreach (array_keys($tables) as $qualified) {
            [$tableSchema, $table] = explode('.', $qualified, 2);
            if ($tableSchema !== $schema) {
                continue;
            }

            if (str_starts_with($index, $table.'_')) {
                return true;
            }

            if (preg_match('/(^|_)'.preg_quote($table, '/').'(_|$)/', $index) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{type: string, schema: ?string, table: ?string, name: ?string}|null
     */
    private static function parse(string $line): ?array
    {
        if (preg_match('/^\d+;\s+\d+\s+\d+\s+(.+)$/', $line, $matches) !== 1) {
            return null;
        }

        $rest = $matches[1];
        $type = null;

        foreach (self::MULTI_WORD_TYPES as $candidate) {
            if (str_starts_with($rest, $candidate.' ')) {
                $type = $candidate;
                $rest = substr($rest, strlen($candidate) + 1);

                break;
            }
        }

        if ($type === null) {
            if (preg_match('/^(\S+)\s+(.*)$/', $rest, $parts) !== 1) {
                return null;
            }
            $type = $parts[1];
            $rest = $parts[2];
        }

        if ($type === 'SCHEMA') {
            // `SCHEMA - demo root`
            if (preg_match('/^-\s+(\S+)\s+\S+\s*$/', $rest, $schemaMatch) === 1) {
                return ['type' => 'SCHEMA', 'schema' => $schemaMatch[1], 'table' => null, 'name' => $schemaMatch[1]];
            }

            return ['type' => 'SCHEMA', 'schema' => null, 'table' => null, 'name' => null];
        }

        // Drop trailing owner token.
        if (preg_match('/^(.*)\s+(\S+)\s*$/', $rest, $owned) !== 1) {
            return ['type' => $type, 'schema' => null, 'table' => null, 'name' => null];
        }

        $body = trim($owned[1]);
        $tokens = preg_split('/\s+/', $body) ?: [];

        if ($tokens === []) {
            return ['type' => $type, 'schema' => null, 'table' => null, 'name' => null];
        }

        $schema = $tokens[0] === '-' ? null : $tokens[0];
        $name = $tokens[1] ?? null;
        $table = null;

        if (in_array($type, ['CONSTRAINT', 'FK CONSTRAINT', 'DEFAULT', 'TRIGGER', 'POLICY', 'RULE'], true)) {
            // `CONSTRAINT demo users users_pkey` / `DEFAULT demo users id`
            $table = $tokens[1] ?? null;
            $name = $tokens[2] ?? $name;
        } elseif (in_array($type, ['TABLE', 'TABLE DATA', 'VIEW', 'MATERIALIZED VIEW', 'FOREIGN TABLE'], true)) {
            $table = $tokens[1] ?? null;
            $name = $table;
        } elseif ($type === 'COMMENT' && ($tokens[1] ?? null) === 'TABLE') {
            // `COMMENT demo TABLE users`
            $table = $tokens[2] ?? null;
            $name = $table;
        } elseif ($type === 'ACL' && count($tokens) >= 2 && ! str_contains((string) ($tokens[1] ?? ''), '(')) {
            // Table ACL: `ACL demo users`; function ACL keeps `hello()` as name.
            $maybeTable = $tokens[1] ?? null;
            if (is_string($maybeTable) && ! str_contains($maybeTable, '(')) {
                $table = $maybeTable;
            }
        }

        return [
            'type' => $type,
            'schema' => $schema,
            'table' => $table,
            'name' => $name,
        ];
    }

    /**
     * Count non-comment TOC entries (what pg_restore would act on).
     */
    public static function entryCount(string $toc): int
    {
        $count = 0;

        foreach (preg_split('/\R/', $toc) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && ! str_starts_with($trimmed, ';')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{entries: int, schemas: list<string>, tables: list<string>}
     */
    public static function summarize(string $toc): array
    {
        $schemas = [];
        $tables = [];

        foreach (preg_split('/\R/', $toc) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, ';')) {
                continue;
            }

            $parsed = self::parse($trimmed);
            if ($parsed === null) {
                continue;
            }

            if ($parsed['type'] === 'SCHEMA' && $parsed['schema'] !== null) {
                $schemas[$parsed['schema']] = true;
            }

            if ($parsed['type'] === 'TABLE' && $parsed['schema'] !== null && $parsed['table'] !== null) {
                $tables[$parsed['schema'].'.'.$parsed['table']] = true;
            }
        }

        return [
            'entries' => self::entryCount($toc),
            'schemas' => array_keys($schemas),
            'tables' => array_keys($tables),
        ];
    }

    /**
     * @param  list<array{schema: string, table: string}>  $tables
     * @return array<string, true>
     */
    private static function qualifiedSet(array $tables): array
    {
        $set = [];

        foreach ($tables as $table) {
            $set[$table['schema'].'.'.$table['table']] = true;
        }

        return $set;
    }
}
