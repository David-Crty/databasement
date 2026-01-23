<?php

namespace App\Livewire\DatabaseServer;

use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DatabaseListService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class RestoreModal extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Locked]
    public ?DatabaseServer $targetServer = null;

    #[Locked]
    public ?string $selectedSourceServerId = null;

    #[Locked]
    public ?string $selectedSnapshotId = null;

    #[Validate('required|string|max:64|regex:/^[a-zA-Z0-9_]+$/', message: [
        'required' => 'Please enter a database name.',
        'regex' => 'Database name can only contain letters, numbers, and underscores.',
    ])]
    public string $schemaName = '';

    public int $currentStep = 1;

    /** @var array<int, string> */
    public array $existingDatabases = [];

    public bool $showModal = false;

    public string $snapshotSearch = '';

    public function updatedSnapshotSearch(): void
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
        $this->reset(['selectedSourceServerId', 'selectedSnapshotId', 'schemaName', 'currentStep', 'existingDatabases', 'snapshotSearch']);
        $this->resetPage('snapshots');
        $this->targetServer = DatabaseServer::find($targetServerId);

        $this->authorize('restore', $this->targetServer);

        $this->currentStep = 1;

        $this->showModal = true;
    }

    public function selectSourceServer(string $serverId): void
    {
        $this->selectedSourceServerId = $serverId;
        $this->selectedSnapshotId = null;
        $this->snapshotSearch = '';
        $this->resetPage('snapshots');
        $this->currentStep = 2;
    }

    public function selectSnapshot(string $snapshotId): void
    {
        $this->selectedSnapshotId = $snapshotId;

        // Pre-fill schema name with original database name
        $snapshot = Snapshot::find($snapshotId);
        if ($snapshot) {
            $this->schemaName = $snapshot->database_name;
        }

        // Load existing databases for autocomplete
        $this->loadExistingDatabases();

        $this->currentStep = 3;
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function loadExistingDatabases(): void
    {
        if (! $this->targetServer) {
            return;
        }

        try {
            $databaseListService = app(DatabaseListService::class);
            $this->existingDatabases = $databaseListService->listDatabases($this->targetServer);
        } catch (\Exception $e) {
            $this->existingDatabases = [];
            // Silently fail - autocomplete just won't work
        }
    }

    public function restore(BackupJobFactory $backupJobFactory): void
    {
        $this->authorize('restore', $this->targetServer);

        // Validate locked properties first
        if (! $this->selectedSourceServerId || ! $this->selectedSnapshotId) {
            $this->error(__('Please select a source server and snapshot before restoring.'));

            return;
        }

        $this->validate();

        try {
            $snapshot = Snapshot::findOrFail($this->selectedSnapshotId);

            $userId = auth()->id();
            $restore = $backupJobFactory->createRestore(
                snapshot: $snapshot,
                targetServer: $this->targetServer,
                schemaName: $this->schemaName,
                triggeredByUserId: is_int($userId) ? $userId : null
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
     * @return \Illuminate\Database\Eloquent\Collection<int, DatabaseServer>|Collection<int, never>
     */
    public function getCompatibleServersProperty(): Collection|\Illuminate\Database\Eloquent\Collection
    {
        if (! $this->targetServer) {
            return collect();
        }

        return DatabaseServer::query()
            ->where('database_type', $this->targetServer->database_type)
            ->whereHas('snapshots', function ($query) {
                $query->whereHas('job', fn ($q) => $q->whereRaw("status = 'completed'"));
            })
            ->with(['snapshots' => function ($query) {
                $query->whereHas('job', fn ($q) => $q->whereRaw("status = 'completed'"))
                    ->with('job')
                    ->orderBy('created_at', 'desc');
            }])
            ->get();
    }

    public function getSelectedSourceServerProperty(): ?DatabaseServer
    {
        if (! $this->selectedSourceServerId) {
            return null;
        }

        return DatabaseServer::with(['snapshots' => function ($query) {
            $query->whereHas('job', fn ($q) => $q->whereRaw("status = 'completed'"))
                ->with('job')
                ->orderBy('created_at', 'desc');
        }])->find($this->selectedSourceServerId);
    }

    public function getSelectedSnapshotProperty(): ?Snapshot
    {
        if (! $this->selectedSnapshotId) {
            return null;
        }

        return Snapshot::find($this->selectedSnapshotId);
    }

    /**
     * @return LengthAwarePaginator<int, Snapshot>|null
     */
    public function getPaginatedSnapshotsProperty(): ?LengthAwarePaginator
    {
        if (! $this->selectedSourceServerId) {
            return null;
        }

        return Snapshot::query()
            ->where('database_server_id', $this->selectedSourceServerId)
            ->whereHas('job', fn ($q) => $q->whereRaw("status = 'completed'"))
            ->when($this->snapshotSearch, function ($query) {
                $query->where('database_name', 'like', '%'.$this->snapshotSearch.'%');
            })
            ->with('job')
            ->orderBy('created_at', 'desc')
            ->paginate(10, pageName: 'snapshots');
    }

    public function render(): View
    {
        return view('livewire.database-server.restore-modal');
    }
}
