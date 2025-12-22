<?php

namespace App\Livewire\Snapshot;

use App\Models\Snapshot;
use App\Queries\SnapshotQuery;
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

    public array $sortBy = ['column' => 'started_at', 'direction' => 'desc'];

    public bool $drawer = false;

    #[Locked]
    public ?string $deleteId = null;

    public bool $showDeleteModal = false;

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
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
        $this->reset('search', 'statusFilter');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    public function headers(): array
    {
        return [
            ['key' => 'started_at', 'label' => __('Started'), 'class' => 'w-48'],
            ['key' => 'server', 'label' => __('Server'), 'sortable' => false],
            ['key' => 'database', 'label' => __('Database'), 'sortable' => false],
            ['key' => 'status', 'label' => __('Status'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'duration', 'label' => __('Duration'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'size', 'label' => __('Size'), 'sortable' => false, 'class' => 'w-40'],
            ['key' => 'method', 'label' => __('Method'), 'sortable' => false, 'class' => 'w-32'],
        ];
    }

    public function statusOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All Statuses')],
            ['id' => 'completed', 'name' => __('Completed')],
            ['id' => 'failed', 'name' => __('Failed')],
            ['id' => 'running', 'name' => __('Running')],
            ['id' => 'pending', 'name' => __('Pending')],
        ];
    }

    public function confirmDelete(string $id)
    {
        $snapshot = Snapshot::findOrFail($id);

        $this->authorize('delete', $snapshot);

        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete()
    {
        if (! $this->deleteId) {
            return;
        }

        $snapshot = Snapshot::findOrFail($this->deleteId);

        $this->authorize('delete', $snapshot);

        $snapshot->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success('Snapshot deleted successfully!', position: 'toast-bottom');
    }

    public function download(string $id): ?BinaryFileResponse
    {
        $snapshot = Snapshot::with('volume')->findOrFail($id);

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

            $this->error('Unsupported storage type.', position: 'toast-bottom');

            return null;
        } catch (\Exception $e) {
            $this->error('Failed to download backup: '.$e->getMessage(), position: 'toast-bottom');

            return null;
        }
    }

    private function downloadLocal(Snapshot $snapshot, string $storagePath): ?BinaryFileResponse
    {
        if (! file_exists($storagePath)) {
            $this->error('Backup file not found.', position: 'toast-bottom');

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

    public function render()
    {
        $snapshots = SnapshotQuery::buildFromParams(
            search: $this->search,
            statusFilter: $this->statusFilter,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(15);

        return view('livewire.snapshot.index', [
            'snapshots' => $snapshots,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
        ])->layout('components.layouts.app', ['title' => __('Snapshots')]);
    }
}
