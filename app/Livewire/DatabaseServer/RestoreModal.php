<?php

namespace App\Livewire\DatabaseServer;

use App\Enums\DatabaseType;
use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\SnapshotQuery;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class RestoreModal extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Locked]
    public ?DatabaseServer $targetServer = null;

    #[Locked]
    public ?string $selectedSnapshotId = null;

    public string $schemaName = '';

    public int $currentStep = 1;

    /** @var array<int, string> */
    public array $existingDatabases = [];

    public bool $showModal = false;

    public bool $forceDatabase = false;

    public string $ownerUser = '';

    public string $snapshotSearch = '';

    public ?string $serverFilter = null;

    public function updatedSnapshotSearch(): void
    {
        $this->resetPage('snapshots');
    }

    public function updatedServerFilter(): void
    {
        $this->resetPage('snapshots');
    }

    /**
     * @return array<int, string>
     */
    public function getFilteredDatabasesProperty(): array
    {
        if (empty($this->schemaName)) {
            return $this->existingDatabases;
        }

        return collect($this->existingDatabases)
            ->filter(fn ($db) => str_contains(strtolower($db), strtolower($this->schemaName)))
            ->values()
            ->all();
    }

    public function selectDatabase(string $database): void
    {
        $this->schemaName = $database;
    }

    public function mount(?string $targetServerId = null): void
    {
        if ($targetServerId) {
            $this->targetServer = DatabaseServer::find($targetServerId);
        }
    }

    #[On('open-restore-modal')]
    public function openModal(string $targetServerId): void
    {
        $this->reset(['selectedSnapshotId', 'schemaName', 'forceDatabase', 'ownerUser', 'currentStep', 'existingDatabases', 'snapshotSearch', 'serverFilter']);
        $this->resetPage('snapshots');
        $this->targetServer = DatabaseServer::find($targetServerId);

        $this->authorize('restore', $this->targetServer);

        $this->showModal = true;
    }

    public function selectSnapshot(string $snapshotId): void
    {
        $this->selectedSnapshotId = $snapshotId;

        // Pre-fill schema name: use target server's first database path for SQLite, otherwise snapshot's database name
        if ($this->targetServer?->database_type === DatabaseType::SQLITE && ! empty($this->targetServer->database_names)) {
            $this->schemaName = $this->targetServer->database_names[0];
        } else {
            $this->schemaName = Snapshot::findOrFail($snapshotId)->database_name;
        }

        // Load existing databases for autocomplete
        $this->loadExistingDatabases();

        $this->currentStep = 2;
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    /**
     * Validate schema name based on database type.
     */
    protected function validateSchemaName(): void
    {
        $isSqlite = $this->targetServer?->database_type === DatabaseType::SQLITE;

        if ($isSqlite) {
            // SQLite uses file paths - allow more characters
            $rules = ['schemaName' => 'required|string|max:255'];
            $messages = ['schemaName.required' => __('Please enter a database path.')];
        } else {
            // MySQL, MariaDB, PostgreSQL - only letters, numbers, and underscores
            $rules = ['schemaName' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/'];
            $messages = [
                'schemaName.required' => __('Please enter a database name.'),
                'schemaName.regex' => __('Database name can only contain letters, numbers, and underscores.'),
            ];
        }

        $this->validate($rules, $messages);
    }

    public function loadExistingDatabases(): void
    {
        if (! $this->targetServer) {
            return;
        }

        try {
            $this->existingDatabases = app(DatabaseProvider::class)->listDatabasesForServer($this->targetServer);
        } catch (\Exception $e) {
            $this->existingDatabases = [];
            // Silently fail - autocomplete just won't work
        }
    }

    public function restore(BackupJobFactory $backupJobFactory): void
    {
        $this->authorize('restore', $this->targetServer);

        // Validate locked properties first
        if (! $this->selectedSnapshotId) {
            $this->error(__('Please select a snapshot before restoring.'));

            return;
        }

        $this->validateSchemaName();

        try {
            $snapshot = Snapshot::findOrFail($this->selectedSnapshotId);

            $userId = auth()->id();
            $restore = $backupJobFactory->createRestore(
                snapshot: $snapshot,
                targetServer: $this->targetServer,
                schemaName: $this->schemaName,
                triggeredByUserId: is_int($userId) ? $userId : null,
                options: array_filter([
                    'force_database' => $this->forceDatabase ?: null,
                    'owner_user' => ($trimmedOwner = trim($this->ownerUser)) !== '' ? $trimmedOwner : null,
                ]),
            );

            ProcessRestoreJob::dispatch($restore->id);

            $this->success('Restore started successfully!');

            $this->showModal = false;

            $this->dispatch('restore-completed');
        } catch (\Exception $e) {
            $this->error('Failed to queue restore: '.$e->getMessage());
        }
    }

    /**
     * Get compatible servers for the filter dropdown.
     *
     * @return Collection<int, DatabaseServer>
     */
    public function getCompatibleServersProperty(): Collection
    {
        if (! $this->targetServer) {
            return collect();
        }

        return DatabaseServer::query()
            ->where('database_type', $this->targetServer->database_type)
            ->whereHas('snapshots', function ($query) {
                $query->whereHas('job', fn ($q) => $q->whereRaw("status = 'completed'"));
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function getSelectedSnapshotProperty(): ?Snapshot
    {
        if (! $this->selectedSnapshotId) {
            return null;
        }

        return Snapshot::with('databaseServer')->find($this->selectedSnapshotId);
    }

    /**
     * Get paginated snapshots for the snapshot list.
     *
     * @return LengthAwarePaginator<int, Snapshot>|null
     */
    public function getPaginatedSnapshotsProperty(): ?LengthAwarePaginator
    {
        if (! $this->targetServer) {
            return null;
        }

        return SnapshotQuery::buildFromParams(
            search: $this->snapshotSearch ?: null,
            statusFilter: 'completed',
            sortColumn: 'created_at',
            sortDirection: 'desc'
        )
            ->whereHas('databaseServer', fn (Builder $q) => $q->whereRaw('database_type = ?', [$this->targetServer->database_type]))
            ->when($this->serverFilter, fn ($q) => $q->where('database_server_id', $this->serverFilter))
            ->paginate(20, pageName: 'snapshots');
    }

    public function render(): View
    {
        return view('livewire.database-server.restore-modal');
    }
}
