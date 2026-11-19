<?php

namespace App\Livewire\Concerns;

use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\RestoreOptions;

/**
 * Holds the destination-step state shared by the restore modals and powers the
 * "destination database" type-ahead.
 *
 * The trait owns the fields the shared `_destination-step` and
 * `_destination-autocomplete` partials bind to. The consuming component must:
 *
 * - implement `updatedTargetServerId()` to react when the target changes
 *   (resolve + authorize the server, then call {@see loadExistingDatabases()})
 * - expose `$this->targetServerOptions` and a `$this->targetServer` accessor
 * - render the shared destination partials.
 */
trait InteractsWithTargetDatabases
{
    /** Selected target server the snapshot is restored onto. */
    public ?string $targetServerId = null;

    /** Destination database name (or SQLite path) to restore into. */
    public string $schemaName = '';

    /** Drop and recreate the database before restoring (MySQL/PostgreSQL only). */
    public bool $forceDatabase = false;

    /** Transfer database ownership to this user after restore (PostgreSQL only). */
    public string $ownerUser = '';

    /** Restore a custom-format snapshot with parallel pg_restore jobs (PostgreSQL only). */
    public bool $parallelRestore = false;

    /**
     * Schemas to restore from a custom-format snapshot (`--schema`). Empty = all.
     *
     * @var list<string>
     */
    public array $selectedRestoreSchemas = [];

    /**
     * Tables to restore (`schema.table` keys). Empty = all tables.
     *
     * @var list<string>
     */
    public array $selectedRestoreTables = [];

    /**
     * Tables to skip (`schema.table` keys). Empty = exclude none.
     *
     * @var list<string>
     */
    public array $selectedExcludeRestoreTables = [];

    /** Schema names when no inventory is available (scheduled restores). Empty = all. */
    public string $restoreSchemasText = '';

    /** Optional `schema.table` list when no inventory is available. */
    public string $restoreTablesText = '';

    /** Optional `schema.table` skip list when no inventory is available. */
    public string $excludeRestoreTablesText = '';

    /** @var array<int, string> */
    public array $existingDatabases = [];

    /**
     * Databases on the target server whose name contains the current input.
     *
     * @return array<int, string>
     */
    public function getFilteredDatabasesProperty(): array
    {
        if ($this->schemaName === '') {
            return $this->existingDatabases;
        }

        return collect($this->existingDatabases)
            ->filter(fn (string $db): bool => str_contains(strtolower($db), strtolower($this->schemaName)))
            ->values()
            ->all();
    }

    public function selectDatabase(string $database): void
    {
        $this->schemaName = $database;
    }

    /**
     * Drop table selections that no longer belong to the chosen schemas.
     */
    public function updatedSelectedRestoreSchemas(): void
    {
        $this->pruneRestoreTableSelections();
    }

    /**
     * Schema choices for the selective-restore multi-select.
     *
     * @param  array{schemas?: list<array{name: string, tables?: list<array{table_name: string, comment: ?string}>}>}|null  $dumpContents
     * @return list<array{id: string, name: string}>
     */
    public static function restoreSchemaOptions(?array $dumpContents): array
    {
        if ($dumpContents === null || empty($dumpContents['schemas'])) {
            return [];
        }

        $options = [];

        foreach ($dumpContents['schemas'] as $schema) {
            if ($schema['name'] === '') {
                continue;
            }

            $options[] = ['id' => $schema['name'], 'name' => $schema['name']];
        }

        return $options;
    }

    /**
     * Table choices for the selective-restore multi-selects, filtered by schema.
     *
     * Empty `$schemas` yields no tables — the UI loads them only after schemas
     * are chosen (and remounts the choices via wire:key).
     *
     * @param  array{schemas?: list<array{name: string, tables?: list<array{table_name: string, comment: ?string}>}>}|null  $dumpContents
     * @param  list<string>  $schemas
     * @return list<array{id: string, name: string}>
     */
    public static function restoreTableOptions(?array $dumpContents, array $schemas = []): array
    {
        if ($dumpContents === null || empty($dumpContents['schemas'])) {
            return [];
        }

        $schemaFilter = array_fill_keys(array_values(array_filter($schemas)), true);

        if ($schemaFilter === []) {
            return [];
        }

        $options = [];

        foreach ($dumpContents['schemas'] as $schema) {
            if ($schema['name'] === '' || ! isset($schemaFilter[$schema['name']])) {
                continue;
            }

            foreach ($schema['tables'] ?? [] as $table) {
                if ($table['table_name'] === '') {
                    continue;
                }

                // Use :: — dots in option ids break Livewire wire:key / snapshot sync.
                $options[] = [
                    'id' => $schema['name'].'::'.$table['table_name'],
                    'name' => $schema['name'].'.'.$table['table_name'],
                ];
            }
        }

        return $options;
    }

    protected function restoreOptions(): RestoreOptions
    {
        $schemas = array_values(array_filter($this->selectedRestoreSchemas));

        if ($schemas === []) {
            $schemas = RestoreOptions::parseSchemaNames($this->restoreSchemasText);
        }

        $tables = self::qualifiedKeysToTables($this->selectedRestoreTables);
        if ($tables === []) {
            $tables = RestoreOptions::parseQualifiedTables($this->restoreTablesText);
        }

        $excludeTables = self::qualifiedKeysToTables($this->selectedExcludeRestoreTables);
        if ($excludeTables === []) {
            $excludeTables = RestoreOptions::parseQualifiedTables($this->excludeRestoreTablesText);
        }

        return new RestoreOptions(
            $this->forceDatabase,
            $this->ownerUser,
            $this->parallelRestore,
            restoreSchemas: $schemas,
            restoreTables: $tables,
            excludeRestoreTables: $excludeTables,
        );
    }

    /**
     * Snapshot inventory used to build / prune table options. Override when the
     * host component has a selected snapshot; scheduled restores leave it null.
     *
     * @return array{schemas?: list<array{name: string, tables?: list<array{table_name: string, comment: ?string}>}>}|null
     */
    protected function snapshotDumpContents(): ?array
    {
        return null;
    }

    protected function pruneRestoreTableSelections(): void
    {
        $allowed = array_column(
            self::restoreTableOptions($this->snapshotDumpContents(), $this->selectedRestoreSchemas),
            'id',
        );

        $this->selectedRestoreTables = array_values(array_intersect($this->selectedRestoreTables, $allowed));
        $this->selectedExcludeRestoreTables = array_values(array_intersect($this->selectedExcludeRestoreTables, $allowed));
    }

    /**
     * @param  list<string>  $schemas
     * @param  list<array{schema: string, table: string}>  $tables
     * @param  list<array{schema: string, table: string}>  $excludeTables
     */
    protected function fillRestoreSelection(array $schemas = [], array $tables = [], array $excludeTables = []): void
    {
        $this->selectedRestoreSchemas = $schemas;
        $this->selectedRestoreTables = self::tablesToQualifiedKeys($tables);
        $this->selectedExcludeRestoreTables = self::tablesToQualifiedKeys($excludeTables);
        $this->restoreSchemasText = implode("\n", $schemas);
        $this->restoreTablesText = self::formatQualifiedTables($tables);
        $this->excludeRestoreTablesText = self::formatQualifiedTables($excludeTables);
    }

    /**
     * @param  list<array{schema: string, table: string}>  $tables
     * @return list<string>
     */
    protected static function tablesToQualifiedKeys(array $tables): array
    {
        return array_map(
            fn (array $table): string => $table['schema'].'::'.$table['table'],
            $tables,
        );
    }

    /**
     * @param  list<mixed>  $keys
     * @return list<array{schema: string, table: string}>
     */
    protected static function qualifiedKeysToTables(array $keys): array
    {
        $tables = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $separator = str_contains($key, '::') ? '::' : (str_contains($key, '.') ? '.' : null);
            if ($separator === null) {
                continue;
            }

            [$schema, $table] = explode($separator, $key, 2);
            $schema = trim($schema);
            $table = trim($table);

            if ($schema !== '' && $table !== '') {
                $tables[] = ['schema' => $schema, 'table' => $table];
            }
        }

        return array_values(array_unique($tables, SORT_REGULAR));
    }

    /**
     * @param  list<array{schema: string, table: string}>  $tables
     */
    protected static function formatQualifiedTables(array $tables): string
    {
        return implode("\n", array_map(
            fn (array $table): string => $table['schema'].'.'.$table['table'],
            $tables,
        ));
    }

    /**
     * Label for a target-server select option, including the host (and port)
     * so servers of the same type or name can be told apart in the dropdown.
     */
    protected function serverOptionLabel(DatabaseServer $server): string
    {
        $location = trim((string) $server->host);

        if ($location !== '' && $server->port) {
            $location .= ':'.$server->port;
        }

        return $location !== '' ? "{$server->name}  ·  {$location}" : $server->name;
    }

    /**
     * Populate {@see $existingDatabases} from the target server, swallowing
     * connection errors so the modal stays usable when the server is offline.
     */
    public function loadExistingDatabases(?DatabaseServer $server): void
    {
        if (! $server) {
            $this->existingDatabases = [];

            return;
        }

        try {
            $this->existingDatabases = app(DatabaseProvider::class)->listDatabasesForServer($server);
        } catch (\Exception) {
            $this->existingDatabases = [];
        }
    }
}
