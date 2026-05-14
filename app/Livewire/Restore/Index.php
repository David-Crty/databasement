<?php

namespace App\Livewire\Restore;

use App\Enums\DatabaseType;
use App\Livewire\Concerns\HandlesJobLogsModal;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Queries\RestoreQuery;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Restores')]
class Index extends Component
{
    use AuthorizesRequests, HandlesJobLogsModal, Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $sourceServerFilter = '';

    #[Url]
    public string $targetServerFilter = '';

    #[Url]
    public string $dbTypeFilter = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?string $deleteRestoreId = null;

    public bool $showDeleteModal = false;

    /**
     * Refresh the list immediately after a restore is created. Without this,
     * the new row only appears on the 5-second poll.
     */
    #[On('restore-created')]
    public function refreshAfterRestoreCreated(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSourceServerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingTargetServerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDbTypeFilter(): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset('search', 'statusFilter', 'sourceServerFilter', 'targetServerFilter', 'dbTypeFilter');
        $this->resetPage();
        $this->success(__('Filters cleared.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'flow', 'label' => __('Source → Target'), 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-52'],
            ['key' => 'status', 'label' => __('Status'), 'class' => 'w-44'],
        ];
    }

    public function getSelectedJobProperty(): ?BackupJob
    {
        if (! $this->selectedJobId) {
            return null;
        }

        // Bypass the OrganizationScope on DatabaseServer/Volume so cross-org
        // deeplinks (e.g. a notification opened while the user is in another
        // org) can still render source/target server context in the logs modal.
        // The job-view policy already gates access to this data.
        return BackupJob::with([
            'restore.snapshot.databaseServer' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.snapshot.volume' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.targetServer' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.triggeredBy',
        ])->find($this->selectedJobId);
    }

    public function openNewRestore(): void
    {
        $this->authorize('create', Restore::class);

        $this->dispatch('open-restore-modal', mode: 'from-restore-index');
    }

    public function rerunRestore(string $restoreId): void
    {
        $restore = Restore::findOrFail($restoreId);

        $this->authorize('view', $restore);
        $this->authorize('create', Restore::class);

        $this->dispatch('open-restore-modal', mode: 'from-restore-index', restoreId: $restoreId);
    }

    public function confirmDeleteRestore(string $restoreId): void
    {
        $restore = Restore::findOrFail($restoreId);

        $this->authorize('delete', $restore);

        $this->deleteRestoreId = $restoreId;
        $this->showDeleteModal = true;
    }

    public function deleteRestore(): void
    {
        if (! $this->deleteRestoreId) {
            return;
        }

        $restore = Restore::findOrFail($this->deleteRestoreId);

        $this->authorize('delete', $restore);

        $restore->delete();
        $this->deleteRestoreId = null;
        $this->showDeleteModal = false;

        $this->success(__('Restore deleted successfully!'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusOptions(): array
    {
        return [
            ['id' => 'completed', 'name' => __('Completed')],
            ['id' => 'failed', 'name' => __('Failed')],
            ['id' => 'running', 'name' => __('Running')],
            ['id' => 'pending', 'name' => __('Pending')],
        ];
    }

    /**
     * Target servers — every database server is a potential restore target.
     *
     * @return array<int, array<string, mixed>>
     */
    public function targetServerOptions(): array
    {
        return DatabaseServer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (DatabaseServer $server) => [
                'id' => $server->id,
                'name' => $server->name,
            ])
            ->toArray();
    }

    /**
     * Source servers — only those that have produced at least one snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sourceServerOptions(): array
    {
        return DatabaseServer::query()
            ->whereHas('snapshots')
            ->orderBy('name')
            ->get()
            ->map(fn (DatabaseServer $server) => [
                'id' => $server->id,
                'name' => $server->name,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dbTypeOptions(): array
    {
        return collect(DatabaseType::cases())
            ->map(fn (DatabaseType $t) => ['id' => $t->value, 'name' => $t->label()])
            ->values()
            ->all();
    }

    public function render(): View
    {
        $restores = RestoreQuery::buildFromParams(
            search: $this->search ?: null,
            statusFilter: $this->statusFilter ?: 'all',
            sourceServerFilter: $this->sourceServerFilter ?: null,
            targetServerFilter: $this->targetServerFilter ?: null,
            dbTypeFilter: $this->dbTypeFilter ?: null,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(15);

        return view('livewire.restore.index', [
            'restores' => $restores,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
            'sourceServerOptions' => $this->sourceServerOptions(),
            'targetServerOptions' => $this->targetServerOptions(),
            'dbTypeOptions' => $this->dbTypeOptions(),
        ]);
    }
}
