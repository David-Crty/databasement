<?php

namespace App\Livewire\BackupJob;

use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Queries\BackupJobQuery;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public string $typeFilter = 'all';

    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $drawer = false;

    public bool $showLogsModal = false;

    public ?string $selectedJobId = null;

    #[Locked]
    public ?string $deleteSnapshotId = null;

    public bool $showDeleteModal = false;

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingTypeFilter()
    {
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (! is_array($property) && $property != '') {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->reset('search', 'statusFilter', 'typeFilter');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    public function headers(): array
    {
        return [
            ['key' => 'type', 'label' => __('Type'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-48'],
            ['key' => 'server', 'label' => __('Server / Database'), 'sortable' => false],
            ['key' => 'status', 'label' => __('Status'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'info', 'label' => __('Info'), 'sortable' => false, 'class' => 'w-32'],
        ];
    }

    public function viewLogs(string $id): void
    {
        $this->selectedJobId = $id;
        $this->showLogsModal = true;
    }

    public function getSelectedJobProperty()
    {
        if (! $this->selectedJobId) {
            return null;
        }

        return BackupJob::with(['snapshot.databaseServer', 'snapshot.triggeredBy', 'restore.snapshot.databaseServer', 'restore.targetServer', 'restore.triggeredBy'])
            ->find($this->selectedJobId);
    }

    public function statusOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All Statuses')],
            ['id' => 'completed', 'name' => __('Completed')],
            ['id' => 'failed', 'name' => __('Failed')],
            ['id' => 'running', 'name' => __('Running')],
            ['id' => 'pending', 'name' => __('Pending')],
            ['id' => 'queued', 'name' => __('Queued')],
        ];
    }

    public function typeOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All Types')],
            ['id' => 'backup', 'name' => __('Backup')],
            ['id' => 'restore', 'name' => __('Restore')],
        ];
    }

    public function download(string $snapshotId): ?BinaryFileResponse
    {
        $snapshot = Snapshot::with('volume')->findOrFail($snapshotId);

        $this->authorize('download', $snapshot);

        try {
            $storageType = $snapshot->getStorageType();
            $storagePath = $snapshot->getStoragePath();

            if ($storageType === 'local') {
                return $this->downloadLocal($snapshot, $storagePath);
            }

            if ($storageType === 's3') {
                $this->downloadS3($snapshot, $storagePath);

                return null;
            }

            $this->error(__('Unsupported storage type.'), position: 'toast-bottom');

            return null;
        } catch (\Exception $e) {
            $this->error(__('Failed to download backup: ').$e->getMessage(), position: 'toast-bottom');

            return null;
        }
    }

    private function downloadLocal(Snapshot $snapshot, string $storagePath): ?BinaryFileResponse
    {
        if (! file_exists($storagePath)) {
            $this->error(__('Backup file not found.'), position: 'toast-bottom');

            return null;
        }

        return response()->file($storagePath, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$snapshot->getFilename().'"',
        ]);
    }

    private function downloadS3(Snapshot $snapshot, string $storagePath): void
    {
        $s3Filesystem = app(Awss3Filesystem::class);
        $presignedUrl = $s3Filesystem->getPresignedUrl(
            $snapshot->volume->config,
            $storagePath,
            expiresInMinutes: 15
        );

        $this->redirect($presignedUrl);
    }

    public function confirmDeleteSnapshot(string $snapshotId): void
    {
        $snapshot = Snapshot::findOrFail($snapshotId);

        $this->authorize('delete', $snapshot);

        $this->deleteSnapshotId = $snapshotId;
        $this->showDeleteModal = true;
    }

    public function deleteSnapshot(): void
    {
        if (! $this->deleteSnapshotId) {
            return;
        }

        $snapshot = Snapshot::findOrFail($this->deleteSnapshotId);

        $this->authorize('delete', $snapshot);

        $snapshot->delete();
        $this->deleteSnapshotId = null;
        $this->showDeleteModal = false;

        $this->success(__('Snapshot deleted successfully!'), position: 'toast-bottom');
    }

    public function render()
    {
        $jobs = BackupJobQuery::buildFromParams(
            search: $this->search,
            statusFilter: $this->statusFilter,
            typeFilter: $this->typeFilter,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(15);

        return view('livewire.backup-job.index', [
            'jobs' => $jobs,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
            'typeOptions' => $this->typeOptions(),
        ])->layout('components.layouts.app', ['title' => __('Jobs')]);
    }
}
