<?php

namespace App\Services\Backup\DTO;

/**
 * Schema/table inventory of a PostgreSQL custom-format dump, taken from
 * `pg_restore --list` (and table comments from the live catalog) before the
 * archive is compressed and uploaded, so the restore UI can offer selective
 * restores without fetching the snapshot.
 */
readonly class DumpContents
{
    /**
     * @param  list<array{name: string, tables: list<array{table_name: string, comment: string|null}>}>  $schemas
     */
    public function __construct(
        public array $schemas,
    ) {}

    /**
     * @return array{schemas: list<array{name: string, tables: list<array{table_name: string, comment: string|null}>}>}
     */
    public function toArray(): array
    {
        return [
            'schemas' => $this->schemas,
        ];
    }

    /**
     * @param  array{schemas?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        $schemas = [];

        foreach ($data['schemas'] ?? [] as $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $name = $schema['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $tables = [];
            foreach ($schema['tables'] ?? [] as $table) {
                if (! is_array($table)) {
                    continue;
                }

                $tableName = $table['table_name'] ?? null;
                if (! is_string($tableName) || $tableName === '') {
                    continue;
                }

                $comment = $table['comment'] ?? null;
                $tables[] = [
                    'table_name' => $tableName,
                    'comment' => is_string($comment) && $comment !== '' ? $comment : null,
                ];
            }

            $schemas[] = [
                'name' => $name,
                'tables' => $tables,
            ];
        }

        return new self($schemas);
    }

    /**
     * Parse a `pg_restore --list` / `-l` table of contents into nested schemas.
     *
     * Lines look like:
     * `5; 2615 2200 SCHEMA - public postgres`
     * `187; 1259 16385 TABLE public users postgres`
     *
     * Comment text is not in the TOC — attach it afterwards with {@see withComments()}.
     */
    public static function fromPgRestoreList(string $toc): self
    {
        /** @var array<string, list<array{table_name: string, comment: string|null}>> $bySchema */
        $bySchema = [];
        /** @var array<string, true> $seenTables */
        $seenTables = [];

        foreach (preg_split('/\R/', $toc) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\d+;\s+\d+\s+\d+\s+SCHEMA\s+-\s+(\S+)\s+/', $line, $matches) === 1) {
                $bySchema[$matches[1]] ??= [];

                continue;
            }

            // "TABLE DATA" is a separate TOC type; do not treat it as TABLE.
            if (preg_match('/^\d+;\s+\d+\s+\d+\s+TABLE DATA\b/', $line) === 1) {
                continue;
            }

            if (preg_match('/^\d+;\s+\d+\s+\d+\s+TABLE\s+(\S+)\s+(\S+)\s+/', $line, $matches) === 1) {
                $schema = $matches[1];
                $tableName = $matches[2];
                $key = $schema.'.'.$tableName;

                if (isset($seenTables[$key])) {
                    continue;
                }

                $seenTables[$key] = true;
                $bySchema[$schema] ??= [];
                $bySchema[$schema][] = [
                    'table_name' => $tableName,
                    'comment' => null,
                ];
            }
        }

        ksort($bySchema);

        $schemas = [];
        foreach ($bySchema as $name => $tables) {
            usort($tables, fn (array $a, array $b): int => $a['table_name'] <=> $b['table_name']);
            $schemas[] = [
                'name' => $name,
                'tables' => $tables,
            ];
        }

        return new self($schemas);
    }

    /**
     * Fill table comments from a map keyed as `schema.table` → comment text.
     *
     * @param  array<string, string|null>  $comments
     */
    public function withComments(array $comments): self
    {
        $schemas = [];

        foreach ($this->schemas as $schema) {
            $tables = [];

            foreach ($schema['tables'] as $table) {
                $key = $schema['name'].'.'.$table['table_name'];
                $comment = $comments[$key] ?? $table['comment'];

                $tables[] = [
                    'table_name' => $table['table_name'],
                    'comment' => is_string($comment) && $comment !== '' ? $comment : null,
                ];
            }

            $schemas[] = [
                'name' => $schema['name'],
                'tables' => $tables,
            ];
        }

        return new self($schemas);
    }

    public function schemaCount(): int
    {
        return count($this->schemas);
    }

    public function tableCount(): int
    {
        return array_sum(array_map(
            fn (array $schema): int => count($schema['tables']),
            $this->schemas,
        ));
    }
}
