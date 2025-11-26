<?php

namespace App\Livewire\Snapshot;

use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'sort')]
    public string $sortField = 'started_at';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

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

    public function sortBy(string $field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
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

            session()->flash('status', 'Snapshot deleted successfully!');
            $this->showDeleteModal = false;
        }
    }

    public function download(string $id, FilesystemProvider $filesystemProvider): StreamedResponse
    {
        $snapshot = Snapshot::with('volume')->findOrFail($id);

        try {
            $filesystem = $filesystemProvider->get($snapshot->volume->type);

            if (! $filesystem->fileExists($snapshot->path)) {
                session()->flash('error', 'Backup file not found.');

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
            session()->flash('error', 'Failed to download backup: '.$e->getMessage());

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
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(15);

        return view('livewire.snapshot.index', [
            'snapshots' => $snapshots,
        ])->layout('components.layouts.app', ['title' => __('Snapshots')]);
    }
}
