<?php

namespace App\Livewire\BackupJob;

use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Queries\BackupJobQuery;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use Illuminate\Contracts\View\View;
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

    /** @var array<string> */
    #[Url]
    public array $statusFilter = [];

    #[Url]
    public string $typeFilter = 'all';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $drawer = false;

    public bool $showLogsModal = false;

    public ?string $selectedJobId = null;

    #[Locked]
    public ?string $deleteSnapshotId = null;

    public bool $showDeleteModal = false;

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
        $this->reset('search', 'statusFilter', 'typeFilter');
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
            $volumeType = $snapshot->volume->type;

            if ($volumeType === 'local') {
                return $this->downloadLocal($snapshot);
            }

            if ($volumeType === 's3') {
                $this->downloadS3($snapshot);

                return null;
            }

            $this->error(__('Unsupported storage type.'), position: 'toast-bottom');

            return null;
        } catch (\Exception $e) {
            $this->error(__('Failed to download backup: ').$e->getMessage(), position: 'toast-bottom');

            return null;
        }
    }

    private function downloadLocal(Snapshot $snapshot): ?BinaryFileResponse
    {
        // Build full path from volume root and filename
        $volumeRoot = $snapshot->volume->config['path'] ?? $snapshot->volume->config['root'] ?? '';
        $fullPath = rtrim($volumeRoot, '/').'/'.$snapshot->filename;

        if (! file_exists($fullPath)) {
            $this->error(__('Backup file not found.'), position: 'toast-bottom');

            return null;
        }

        return response()->file($fullPath, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.basename($snapshot->filename).'"',
        ]);
    }

    private function downloadS3(Snapshot $snapshot): void
    {
        $s3Filesystem = app(Awss3Filesystem::class);
        $presignedUrl = $s3Filesystem->getPresignedUrl(
            $snapshot->volume->config,
            $snapshot->filename,
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

    public function render(): View
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
