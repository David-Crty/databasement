<?php

namespace App\Livewire\Restore;

use App\Enums\BackupJobStatus;
use App\Enums\DatabaseType;
use App\Livewire\Concerns\CancelsJobs;
use App\Livewire\Concerns\ConfirmsDeletion;
use App\Livewire\Concerns\FiltersAndPaginates;
use App\Livewire\Concerns\HandlesJobLogsModal;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Queries\RestoreQuery;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Restores')]
class Index extends Component
{
    use AuthorizesRequests, CancelsJobs, ConfirmsDeletion, FiltersAndPaginates, HandlesJobLogsModal, Toast, WithPagination;

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

    /**
     * Refresh the list immediately after a restore is created. Without this,
     * the new row only appears on the 5-second poll.
     */
    #[On('restore-created')]
    public function refreshAfterRestoreCreated(): void
    {
        $this->resetPage();
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

        // Bypass the organization scopes so cross-org deeplinks (e.g. a
        // notification opened while the user is in another org) can still
        // render source/target server context in the logs modal. Read-only:
        // guardSelectedJob() applies BackupJobPolicy@view to what comes back.
        return $this->guardSelectedJob(BackupJob::with([
            'restore' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.snapshot.databaseServer' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.snapshot.files.volume' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.targetServer' => fn ($q) => $q->withoutGlobalScopes(),
            'restore.triggeredBy',
        ])->find($this->selectedJobId));
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
        $this->confirmDeletion(Restore::query(), $restoreId);
    }

    public function deleteRestore(): void
    {
        $restore = $this->pendingDeletion(Restore::query());

        if ($restore === null) {
            return;
        }

        $restore->delete();
        $this->closeDeletion();

        $this->success(__('Restore deleted successfully!'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusOptions(): array
    {
        return BackupJobStatus::filterOptions();
    }

    /**
     * Target servers — every database server is a potential restore target.
     *
     * @return array<int, array<string, mixed>>
     */
    public function targetServerOptions(): array
    {
        return DatabaseServer::toSelectOptions();
    }

    /**
     * Source servers — only those that have produced at least one snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sourceServerOptions(): array
    {
        return DatabaseServer::toSelectOptions(fn (Builder $query) => $query->whereHas('snapshots'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dbTypeOptions(): array
    {
        return DatabaseType::toSelectOptions();
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

    /**
     * @return list<string>
     */
    protected function filterProperties(): array
    {
        return ['search', 'statusFilter', 'sourceServerFilter', 'targetServerFilter', 'dbTypeFilter'];
    }
}
