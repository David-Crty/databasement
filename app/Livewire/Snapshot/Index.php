<?php

namespace App\Livewire\Snapshot;

use App\Enums\BackupJobStatus;
use App\Enums\DatabaseType;
use App\Livewire\Concerns\FiltersAndPaginates;
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
    use AuthorizesRequests, FiltersAndPaginates, HandlesJobLogsModal, Toast, WithPagination;

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

    #[Locked]
    public ?string $downloadSnapshotId = null;

    public bool $showDownloadModal = false;

    #[Locked]
    public ?string $noteSnapshotId = null;

    public string $noteInput = '';

    public bool $showNoteModal = false;

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
            'snapshot.files.volume' => fn ($q) => $q->withoutGlobalScopes(),
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
        return DatabaseType::toSelectOptions();
    }

    /**
     * Open the volume picker for a snapshot stored on several volumes.
     */
    public function openDownloadModal(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);

        $this->authorize('download', $snapshot);

        $this->downloadSnapshotId = $snapshotId;
        $this->showDownloadModal = true;
    }

    public function getDownloadSnapshotProperty(): ?Snapshot
    {
        if (! $this->downloadSnapshotId) {
            return null;
        }

        return Snapshot::with('files.volume')->find($this->downloadSnapshotId);
    }

    public function openNoteModal(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);

        $this->authorize('update', $snapshot);

        $this->noteSnapshotId = $snapshotId;
        $this->noteInput = $snapshot->note ?? '';
        $this->showNoteModal = true;
    }

    public function saveNote(): void
    {
        if (! $this->noteSnapshotId) {
            return;
        }

        $snapshot = Snapshot::findOrFail($this->noteSnapshotId);

        $this->authorize('update', $snapshot);

        $this->validate(['noteInput' => ['nullable', 'string', 'max:2000']]);

        $snapshot->update(['note' => $this->noteInput !== '' ? $this->noteInput : null]);

        $this->noteSnapshotId = null;
        $this->showNoteModal = false;

        $this->success(__('Note saved successfully!'));
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

    /**
     * @return list<string>
     */
    protected function filterProperties(): array
    {
        return ['search', 'statusFilter', 'serverFilter', 'dbTypeFilter', 'fileMissing'];
    }
}
