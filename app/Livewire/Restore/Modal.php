<?php

namespace App\Livewire\Restore;

use App\Enums\DatabaseType;
use App\Enums\RestoreModalMode;
use App\Jobs\ProcessRestoreJob;
use App\Livewire\Concerns\InteractsWithTargetDatabases;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Queries\SnapshotQuery;
use App\Services\Backup\BackupJobFactory;
use App\Traits\Toast;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Modal extends Component
{
    use AuthorizesRequests, InteractsWithTargetDatabases, Toast, WithPagination;

    public RestoreModalMode $mode = RestoreModalMode::FromServer;

    #[Locked]
    public ?DatabaseServer $targetServer = null;

    #[Locked]
    public ?string $selectedSnapshotId = null;

    public int $currentStep = 1;

    public bool $showModal = false;

    public string $snapshotSearch = '';

    public ?string $serverFilter = null;

    public ?string $dbTypeFilter = null;

    public function updatedSnapshotSearch(): void
    {
        $this->resetPage('snapshots');
    }

    public function updatedServerFilter(): void
    {
        $this->resetPage('snapshots');
    }

    public function updatedDbTypeFilter(): void
    {
        // The previously chosen source server may not match the new type; if
        // we left it in place the query would filter by an incompatible server
        // and the list would collapse to empty.
        $this->serverFilter = null;
        $this->resetPage('snapshots');
    }

    #[On('open-restore-modal')]
    public function openModal(string $mode = 'from-server', ?string $targetServerId = null, ?string $snapshotId = null, ?string $restoreId = null): void
    {
        $this->reset([
            'targetServer', 'targetServerId', 'selectedSnapshotId', 'schemaName', 'forceDatabase',
            'ownerUser', 'currentStep', 'existingDatabases', 'snapshotSearch',
            'serverFilter', 'dbTypeFilter',
        ]);
        $this->resetPage('snapshots');

        $this->mode = RestoreModalMode::from($mode);

        $shouldOpen = match ($this->mode) {
            RestoreModalMode::FromServer => $this->initFromServer($targetServerId),
            RestoreModalMode::FromSnapshot => $this->initFromSnapshot($snapshotId),
            RestoreModalMode::FromRestoreIndex => $this->initFromRestoreIndex($restoreId),
        };

        if ($shouldOpen) {
            $this->showModal = true;
        }
    }

    protected function initFromServer(?string $targetServerId): bool
    {
        if (! $targetServerId) {
            abort(422, 'targetServerId is required for from-server mode.');
        }

        $this->targetServer = DatabaseServer::findOrFail($targetServerId);
        $this->authorize('restore', $this->targetServer);

        // Agent-backed servers aren't directly reachable, so they can't be restore targets.
        if ($this->targetServer->agent_id !== null) {
            abort(422, 'Restores cannot target agent-backed servers.');
        }

        return true;
    }

    protected function initFromSnapshot(?string $snapshotId): bool
    {
        if (! $snapshotId) {
            abort(422, 'snapshotId is required for from-snapshot mode.');
        }

        $snapshot = Snapshot::findOrFail($snapshotId);
        $this->authorize('restoreFrom', $snapshot);

        $this->selectedSnapshotId = $snapshotId;
        $this->dbTypeFilter = $snapshot->database_type->value;

        return true;
    }

    protected function initFromRestoreIndex(?string $restoreId = null): bool
    {
        $this->authorize('create', Restore::class);

        if (! $restoreId) {
            return true;
        }

        $restore = Restore::with(['snapshot', 'targetServer'])->findOrFail($restoreId);

        $snapshot = $restore->snapshot;
        $target = $restore->targetServer;

        // OrganizationScope on DatabaseServer returns null for cross-org rows
        // even though the FK is cascade-deleted — PHPDoc on the relation
        // doesn't model that. This null check is the cross-org guard.
        // @phpstan-ignore booleanNot.alwaysFalse, booleanNot.alwaysFalse, booleanOr.alwaysFalse
        if (! $snapshot || ! $target) {
            $this->error(__('Cannot re-run: the original snapshot or target server no longer exists.'));

            return false;
        }

        $this->authorize('restoreFrom', $snapshot);
        $this->authorize('restore', $target);

        $this->selectedSnapshotId = $snapshot->id;
        $this->dbTypeFilter = $snapshot->database_type->value;
        $this->targetServer = $target;
        $this->targetServerId = $target->id;
        $this->schemaName = $restore->schema_name;
        $this->forceDatabase = (bool) ($restore->options['force_database'] ?? false);
        $this->ownerUser = (string) ($restore->options['owner_user'] ?? '');
        $this->loadExistingDatabases($this->targetServer);
        $this->currentStep = 2;

        return true;
    }

    public function selectSnapshot(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);
        $this->selectedSnapshotId = $snapshotId;

        if ($this->mode === RestoreModalMode::FromRestoreIndex) {
            // Need to pick target server next.
            $this->dbTypeFilter = $snapshot->database_type->value;
            $this->currentStep = 2;

            return;
        }

        // from-server: target already set, advance to configure step.
        $this->prefillSchemaNameAndDatabases($snapshot);
        $this->currentStep = 2;
    }

    public function updatedTargetServerId(): void
    {
        if (! $this->targetServerId) {
            $this->targetServer = null;
            $this->existingDatabases = [];

            return;
        }

        $server = DatabaseServer::findOrFail($this->targetServerId);
        $this->authorize('restore', $server);
        $this->targetServer = $server;

        $snapshot = $this->selectedSnapshotId
            ? Snapshot::findOrFail($this->selectedSnapshotId)
            : null;

        if ($snapshot) {
            $this->prefillSchemaNameAndDatabases($snapshot);
        } else {
            $this->loadExistingDatabases($server);
        }
    }

    protected function prefillSchemaNameAndDatabases(Snapshot $snapshot): void
    {
        if ($this->targetServer?->database_type === DatabaseType::SQLITE) {
            $paths = $this->targetServer->resolveDatabaseNames();
            $this->schemaName = $paths[0] ?? $snapshot->database_name;
        } else {
            $this->schemaName = $snapshot->database_name;
        }

        $this->loadExistingDatabases($this->targetServer);
    }

    public function previousStep(): void
    {
        if ($this->currentStep <= 1) {
            return;
        }

        // When stepping back, clear the selection made on the step we're leaving.
        if ($this->mode === RestoreModalMode::FromRestoreIndex) {
            // Destination -> snapshot picker: drop the chosen target and reset the
            // snapshot selection/filters (the auto-applied dbTypeFilter was pinned
            // to the previous snapshot) so the user starts the pick fresh.
            $this->targetServer = null;
            $this->targetServerId = null;
            $this->existingDatabases = [];
            $this->selectedSnapshotId = null;
            $this->dbTypeFilter = null;
            $this->serverFilter = null;
            $this->snapshotSearch = '';
            $this->resetPage('snapshots');
        } else {
            // from-server: destination -> snapshot picker: clear chosen snapshot.
            $this->selectedSnapshotId = null;
        }

        $this->currentStep--;
    }

    /**
     * Validate schema name based on database type.
     */
    protected function validateSchemaName(): void
    {
        $type = $this->targetServer->database_type;

        $this->validate(
            ['schemaName' => $type->databaseNameRules()],
            $type->databaseNameMessages('schemaName'),
        );
    }

    public function restore(BackupJobFactory $backupJobFactory): void
    {
        if (! $this->targetServer) {
            $this->error(__('Please select a target server before restoring.'));

            return;
        }

        $this->authorize('restore', $this->targetServer);

        // Defend against crafted requests that bypass the UI picker (agent-backed targets are unreachable).
        if ($this->targetServer->agent_id !== null) {
            $this->error(__('Restores cannot target agent-backed servers.'));

            return;
        }

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
                options: $this->buildOptions(),
            );

            ProcessRestoreJob::dispatch($restore->id);

            $this->success(__('Restore started successfully!'));

            $this->showModal = false;

            $this->dispatch('restore-created');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
            $this->error(__('Failed to queue restore. Please try again.'));
        }
    }

    public function getSelectedSnapshotProperty(): ?Snapshot
    {
        if (! $this->selectedSnapshotId) {
            return null;
        }

        return Snapshot::with('databaseServer')->find($this->selectedSnapshotId);
    }

    /**
     * Servers available as snapshot-source filter on step 1 (only used in
     * from-server and from-restore-index modes).
     *
     * @return Collection<int, DatabaseServer>
     */
    public function getCompatibleServersProperty(): Collection
    {
        $query = DatabaseServer::query()
            ->whereHas('snapshots', function ($q) {
                $q->whereHas('job', fn ($jq) => $jq->whereRaw("status = 'completed'"));
            });

        if ($this->mode === RestoreModalMode::FromServer && $this->targetServer) {
            $query->whereRaw('database_type = ?', [$this->targetServer->database_type->value]);
        } elseif ($this->dbTypeFilter) {
            $query->whereRaw('database_type = ?', [$this->dbTypeFilter]);
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return LengthAwarePaginator<int, Snapshot>|null
     */
    public function getPaginatedSnapshotsProperty(): ?LengthAwarePaginator
    {
        $dbType = $this->mode === RestoreModalMode::FromServer
            ? $this->targetServer?->database_type?->value
            : $this->dbTypeFilter;

        // from-server requires a target before we can list compatible snapshots.
        if ($this->mode === RestoreModalMode::FromServer && ! $this->targetServer) {
            return null;
        }

        return SnapshotQuery::buildFromParams(
            search: $this->snapshotSearch ?: null,
            statusFilter: 'completed',
            sortColumn: 'created_at',
            sortDirection: 'desc',
        )
            ->when($dbType, fn (Builder $q) => $q->whereRaw('database_type = ?', [$dbType]))
            ->when($this->serverFilter, fn ($q) => $q->where('database_server_id', $this->serverFilter))
            ->whereHas('job', fn (Builder $q) => $q->whereRaw("status = 'completed'"))
            ->whereRaw('file_exists = ?', [true])
            ->paginate(20, pageName: 'snapshots');
    }

    /**
     * Target servers available for selection when the user has already picked
     * a snapshot (from-snapshot and from-restore-index modes).
     *
     * @return Collection<int, DatabaseServer>
     */
    public function getCompatibleTargetServersProperty(): Collection
    {
        if (! $this->selectedSnapshotId) {
            return collect();
        }

        $snapshot = Snapshot::find($this->selectedSnapshotId);

        if (! $snapshot) {
            return collect();
        }

        return DatabaseServer::query()
            ->whereRaw('database_type = ?', [$snapshot->database_type->value])
            ->whereNull('agent_id')
            ->where('database_type', '!=', DatabaseType::REDIS->value)
            ->orderBy('name')
            ->get(['id', 'name', 'database_type', 'host', 'port']);
    }

    /**
     * Target-server options for the destination-step select (from-snapshot and
     * from-restore-index modes).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getTargetServerOptionsProperty(): array
    {
        return $this->getCompatibleTargetServersProperty()
            ->map(fn (DatabaseServer $s) => [
                'id' => $s->id,
                'name' => $this->serverOptionLabel($s),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function dbTypeOptions(): array
    {
        return collect(DatabaseType::cases())
            ->reject(fn (DatabaseType $t) => $t === DatabaseType::REDIS)
            ->map(fn (DatabaseType $t) => ['id' => $t->value, 'name' => $t->label()])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function stepLabels(): array
    {
        return match ($this->mode) {
            RestoreModalMode::FromServer => [__('Select Snapshot'), __('Destination')],
            RestoreModalMode::FromSnapshot => [__('Destination')],
            RestoreModalMode::FromRestoreIndex => [__('Select Snapshot'), __('Destination')],
        };
    }

    public function isConfigureStep(): bool
    {
        return $this->currentStep === $this->mode->totalSteps();
    }

    public function render(): View
    {
        return view('livewire.restore.modal');
    }
}
