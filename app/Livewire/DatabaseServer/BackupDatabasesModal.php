<?php

namespace App\Livewire\DatabaseServer;

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Traits\RunsServerBackups;
use App\Traits\Toast;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One-off backup of a subset of the databases each backup configuration of a
 * server covers. The configurations themselves are left untouched.
 */
class BackupDatabasesModal extends Component
{
    use AuthorizesRequests, RunsServerBackups, Toast;

    public const string SOURCE_CONFIGURED = 'configured';

    public const string SOURCE_SERVER = 'server';

    public const string SOURCE_HISTORY = 'history';

    public bool $showModal = false;

    public bool $loaded = false;

    #[Locked]
    public ?string $serverId = null;

    #[Locked]
    public string $serverName = '';

    /**
     * Per backup id, what can be picked for it, or why nothing can: the
     * listing failed (`unlisted`, with the reason in `detail`), nothing
     * matches (`empty`), or the agent never backed anything up (`no-history`).
     *
     * @var array<string, array{label: string, source: string, options: list<array{id: string, name: string}>, problem: ?string, detail: ?string}>
     */
    #[Locked]
    public array $configurations = [];

    /**
     * Per backup id, the databases picked for this run.
     *
     * @var array<string, list<string>>
     */
    public array $selections = [];

    #[On('open-backup-databases-modal')]
    public function openModal(string $serverId): void
    {
        $server = $this->server($serverId);

        $this->serverId = $server->id;
        $this->serverName = $server->name;
        $this->configurations = [];
        $this->selections = [];
        $this->loaded = false;
        $this->resetErrorBag();
        $this->showModal = true;

        $this->js('$wire.loadDatabases()');
    }

    public function loadDatabases(): void
    {
        if ($this->serverId === null) {
            return;
        }

        $server = $this->server($this->serverId);
        $serverDatabases = null;

        foreach ($server->backups as $backup) {
            $source = $this->sourceFor($server, $backup);
            $problem = null;
            $detail = null;
            $databases = [];

            try {
                $databases = match ($source) {
                    self::SOURCE_CONFIGURED => $backup->database_names ?? [],
                    self::SOURCE_SERVER => $this->withinScope($backup, $serverDatabases ??= app(DatabaseProvider::class)->listDatabasesForServer($server)),
                    self::SOURCE_HISTORY => $this->withinScope($backup, $this->previouslyBackedUp($backup)),
                };
            } catch (\Throwable $e) {
                $problem = 'unlisted';
                $detail = $e->getMessage();
            }

            if ($problem === null && $databases === []) {
                $problem = $source === self::SOURCE_HISTORY ? 'no-history' : 'empty';
            }

            $this->configurations[$backup->id] = [
                'label' => $backup->getDisplayLabel(),
                'source' => $source,
                'options' => array_values(array_map(fn (string $name): array => ['id' => $name, 'name' => $name], $databases)),
                'problem' => $problem,
                'detail' => $detail,
            ];
            $this->selections[$backup->id] = [];
        }

        $this->loaded = true;
    }

    public function backup(): void
    {
        if ($this->serverId === null) {
            return;
        }

        $server = $this->server($this->serverId);

        $databaseNames = [];

        foreach ($this->selections as $backupId => $names) {
            $available = array_column($this->configurations[$backupId]['options'] ?? [], 'id');
            $names = array_values(array_intersect(array_map('strval', (array) $names), $available));

            if ($names !== []) {
                $databaseNames[$backupId] = $names;
            }
        }

        if ($databaseNames === []) {
            $this->addError('selections', __('Select at least one database to back up.'));

            return;
        }

        $this->triggerBackups(
            $server->backups->filter(fn (Backup $backup): bool => isset($databaseNames[$backup->id])),
            $databaseNames,
        );

        $this->showModal = false;
    }

    public function render(): View
    {
        return view('livewire.database-server.backup-databases-modal');
    }

    private function server(string $serverId): DatabaseServer
    {
        $server = DatabaseServer::with(['backups.volumes', 'backups.backupSchedule'])->findOrFail($serverId);

        $this->authorize('backup', $server);
        abort_if($server->database_type === DatabaseType::REDIS, 403);

        return $server;
    }

    private function sourceFor(DatabaseServer $server, Backup $backup): string
    {
        return match (true) {
            $server->database_type->identifiesDatabasesByPath(),
            $backup->database_selection_mode === DatabaseSelectionMode::Selected => self::SOURCE_CONFIGURED,
            $server->agent_id === null => self::SOURCE_SERVER,
            default => self::SOURCE_HISTORY,
        };
    }

    /**
     * @param  array<string>  $databases
     * @return array<string>
     */
    private function withinScope(Backup $backup, array $databases): array
    {
        if ($backup->database_selection_mode === DatabaseSelectionMode::Pattern) {
            return DatabaseServer::filterDatabasesByPattern($databases, $backup->database_include_pattern ?? '');
        }

        return $databases;
    }

    /**
     * The databases an agent can reach are only known from what it backed up
     * before: the app cannot list them itself.
     *
     * @return array<int, string>
     */
    private function previouslyBackedUp(Backup $backup): array
    {
        return Snapshot::query()
            ->where('backup_id', $backup->id)
            ->completed()
            ->distinct()
            ->orderBy('database_name')
            ->pluck('database_name')
            ->all();
    }
}
