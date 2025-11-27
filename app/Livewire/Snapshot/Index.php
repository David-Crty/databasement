<?php

namespace App\Livewire\Snapshot;

use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    use Toast, WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public array $sortBy = ['column' => 'started_at', 'direction' => 'desc'];

    public bool $drawer = false;

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
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete()
    {
        if ($this->deleteId) {
            $snapshot = Snapshot::findOrFail($this->deleteId);
            $snapshot->delete();
            $this->deleteId = null;

            $this->success('Snapshot deleted successfully!', position: 'toast-bottom');
            $this->showDeleteModal = false;
        }
    }

    public function download(string $id, FilesystemProvider $filesystemProvider): StreamedResponse
    {
        $snapshot = Snapshot::with('volume')->findOrFail($id);

        try {
            $filesystem = $filesystemProvider->get($snapshot->volume->type);

            if (! $filesystem->fileExists($snapshot->path)) {
                $this->error('Backup file not found.', position: 'toast-bottom');

                return response()->streamDownload(function () {}, 'error.txt');
            }

            $stream = $filesystem->readStream($snapshot->path);

            return response()->streamDownload(function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, basename($snapshot->path), [
                'Content-Type' => 'application/gzip',
                'Content-Length' => $snapshot->file_size,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to download backup: '.$e->getMessage(), position: 'toast-bottom');

            return response()->streamDownload(function () {}, 'error.txt');
        }
    }

    public function render()
    {
        $snapshots = Snapshot::query()
            ->with(['databaseServer', 'backup', 'volume', 'triggeredBy'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->whereHas('databaseServer', function ($sq) {
                        $sq->where('name', 'like', '%'.$this->search.'%');
                    })
                        ->orWhere('database_name', 'like', '%'.$this->search.'%')
                        ->orWhere('database_host', 'like', '%'.$this->search.'%')
                        ->orWhere('path', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(15);

        return view('livewire.snapshot.index', [
            'snapshots' => $snapshots,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
        ])->layout('components.layouts.app', ['title' => __('Snapshots')]);
    }
}
