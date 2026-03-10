<?php

namespace App\Livewire\BackupJob;

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\BackupJobQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

#[Title('Jobs')]
class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $serverFilter = '';

    #[Url]
    public string $fileMissing = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $showLogsModal = false;

    #[Url(as: 'job')]
    public ?string $selectedJobId = null;

    #[Locked]
    public ?string $deleteSnapshotId = null;

    public bool $showDeleteModal = false;

    public bool $keepFiles = false;

    public function mount(): void
    {
        // If a job ID is in the URL, validate and authorize before opening modal
        if ($this->selectedJobId) {
            $job = BackupJob::find($this->selectedJobId);

            if (! $job) {
                session()->flash('error', __('Job not found: ').$this->selectedJobId);
                $this->selectedJobId = null;

                return;
            }

            $this->authorize('view', $job);

            $this->showLogsModal = true;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingServerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFileMissing(): void
    {
        $this->resetPage();
    }

    /**
     * @param  string|array<string, mixed>  $property
     */
    public function updated(string|array $property): void
    {
        if (! is_array($property) && $property != '') {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->reset('search', 'statusFilter', 'typeFilter', 'serverFilter', 'fileMissing');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'type', 'label' => __('Type'), 'class' => 'w-32'],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-48'],
            ['key' => 'server', 'label' => __('Server / Database'), 'sortable' => false],
            ['key' => 'status', 'label' => __('Status'), 'class' => 'w-32'],
            ['key' => 'duration_ms', 'label' => __('Duration'), 'class' => 'w-28'],
            ['key' => 'snapshot_size', 'label' => __('Size'), 'class' => 'w-28'],
        ];
    }

    public function viewLogs(string $id): void
    {
        $this->selectedJobId = $id;
        $this->showLogsModal = true;
    }

    public function closeLogs(): void
    {
        $this->showLogsModal = false;
        $this->selectedJobId = null;
    }

    public function getSelectedJobProperty(): ?BackupJob
    {
        if (! $this->selectedJobId) {
            return null;
        }

        return BackupJob::with(['snapshot.databaseServer', 'snapshot.triggeredBy', 'restore.snapshot.databaseServer', 'restore.targetServer', 'restore.triggeredBy'])
            ->find($this->selectedJobId);
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
    public function typeOptions(): array
    {
        return [
            ['id' => 'backup', 'name' => __('Backup')],
            ['id' => 'restore', 'name' => __('Restore')],
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

    public function confirmDeleteSnapshot(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);

        $this->authorize('delete', $snapshot);

        $this->deleteSnapshotId = $snapshotId;
        $this->keepFiles = false;
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

        $this->success(__('Snapshot deleted successfully!'), position: 'toast-bottom');
    }

    public function render(): View
    {
        $jobs = BackupJobQuery::buildFromParams(
            search: $this->search,
            statusFilter: $this->statusFilter !== '' ? [$this->statusFilter] : [],
            typeFilter: $this->typeFilter,
            serverFilter: $this->serverFilter,
            fileMissing: $this->fileMissing !== '',
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(15);

        return view('livewire.backup-job.index', [
            'jobs' => $jobs,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
            'typeOptions' => $this->typeOptions(),
            'serverOptions' => $this->serverOptions(),
        ]);
    }
}
