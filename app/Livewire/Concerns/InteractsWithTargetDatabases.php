<?php

namespace App\Livewire\Concerns;

use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;

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
     * Build the per-restore options map, omitting empty values.
     *
     * @return array<string, mixed>
     */
    protected function buildOptions(): array
    {
        return array_filter([
            'force_database' => $this->forceDatabase ?: null,
            'owner_user' => ($owner = trim($this->ownerUser)) !== '' ? $owner : null,
        ]);
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
