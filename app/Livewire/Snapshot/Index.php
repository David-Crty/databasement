<?php

namespace App\Livewire\Snapshot;

use App\Enums\BackupJobStatus;
use App\Enums\DatabaseType;
use App\Livewire\Concerns\HandlesJobLogsModal;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\SnapshotQuery;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Snapshots')]
class Index extends Component
{
    use AuthorizesRequests, HandlesJobLogsModal, Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $serverFilter = '';

    #[Url]
    public string $dbTypeFilter = '';

    #[Url]
    public string $fileMissing = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?string $deleteSnapshotId = null;

    #[Locked]
    public ?string $cancelJobId = null;

    public bool $showDeleteModal = false;

    public bool $keepFiles = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingServerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDbTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFileMissing(): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset('search', 'statusFilter', 'serverFilter', 'dbTypeFilter', 'fileMissing');
        $this->resetPage();
        $this->success(__('Filters cleared.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'subject', 'label' => __('Database'), 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-48'],
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
        // org) can still render the snapshot/server context in the logs modal.
        // The job-view policy already gates access to this data.
        return BackupJob::with([
            'snapshot.databaseServer' => fn ($q) => $q->withoutGlobalScopes(),
            'snapshot.volume' => fn ($q) => $q->withoutGlobalScopes(),
            'snapshot.triggeredBy',
        ])->find($this->selectedJobId);
    }

    public function triggerRestore(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);

        $this->authorize('restoreFrom', $snapshot);

        $this->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshotId);
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
     * @return array<int, array<string, mixed>>
     */
    public function serverOptions(): array
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
     * @return array<int, array<string, mixed>>
     */
    public function dbTypeOptions(): array
    {
        return collect(DatabaseType::cases())
            ->map(fn (DatabaseType $t) => ['id' => $t->value, 'name' => $t->label()])
            ->values()
            ->all();
    }

    public function confirmDeleteSnapshot(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);

        $this->authorize('delete', $snapshot);

        $this->deleteSnapshotId = $snapshotId;
        $this->cancelJobId = null;
        $this->keepFiles = false;
        $this->showDeleteModal = true;
    }

    public function confirmCancelJob(string $jobId): void
    {
        $job = BackupJob::findOrFail($jobId);

        $this->authorize('delete', $job);

        $this->cancelJobId = $jobId;
        $this->deleteSnapshotId = null;
        $this->showDeleteModal = true;
    }

    public function deleteSnapshot(): void
    {
        if (! $this->deleteSnapshotId) {
            return;
        }

        $snapshot = Snapshot::findOrFail($this->deleteSnapshotId);

        $this->authorize('delete', $snapshot);

        $snapshot->skipFileCleanup = $this->keepFiles;
        $snapshot->delete();
        $this->deleteSnapshotId = null;
        $this->showDeleteModal = false;

        $this->success(__('Snapshot deleted successfully!'));
    }

    public function deletePendingJob(): void
    {
        if (! $this->cancelJobId) {
            return;
        }

        $job = BackupJob::findOrFail($this->cancelJobId);

        $this->authorize('delete', $job);

        if ($job->status !== BackupJobStatus::Pending) {
            $this->error(__('Job is no longer pending and cannot be deleted.'));
            $this->showDeleteModal = false;

            return;
        }

        $job->delete();
        $this->cancelJobId = null;
        $this->showDeleteModal = false;

        $this->success(__('Job deleted successfully!'));
    }

    public function render(): View
    {
        $snapshots = SnapshotQuery::buildFromParams(
            search: $this->search ?: null,
            statusFilter: $this->statusFilter ?: 'all',
            serverFilter: $this->serverFilter ?: null,
            dbTypeFilter: $this->dbTypeFilter ?: null,
            fileMissing: $this->fileMissing !== '',
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(15);

        return view('livewire.snapshot.index', [
            'snapshots' => $snapshots,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
            'serverOptions' => $this->serverOptions(),
            'dbTypeOptions' => $this->dbTypeOptions(),
        ]);
    }
}
