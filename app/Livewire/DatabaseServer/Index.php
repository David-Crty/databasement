<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use App\Services\Backup\BackupTask;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toaster;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sortField = 'created_at';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

    public ?string $deleteId = null;

    public ?string $restoreId = null;

    public bool $showDeleteModal = false;

    public function updatingSearch()
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
            DatabaseServer::findOrFail($this->deleteId)->delete();
            $this->deleteId = null;

            session()->flash('status', 'Database server deleted successfully!');
            $this->showDeleteModal = false;
        }
    }

    public function confirmRestore(string $id)
    {
        $this->restoreId = $id;
        $this->dispatch('open-restore-modal', targetServerId: $id);
    }

    public function runBackup(string $id, BackupTask $backupTask)
    {
        try {
            $server = DatabaseServer::with(['backup.volume'])->findOrFail($id);

            if (! $server->backup) {
                Toaster::error('No backup configuration found for this database server.');

                return;
            }

            $snapshot = $backupTask->run($server, 'manual', auth()->id());

            Toaster::success("Backup completed successfully! Snapshot ID: {$snapshot->id}");
        } catch (\Throwable $e) {
            Toaster::error('Backup failed: '.$e->getMessage());
        }
    }

    public function render()
    {
        $servers = DatabaseServer::query()
            ->with(['backup.volume'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('host', 'like', '%'.$this->search.'%')
                        ->orWhere('database_type', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(10);

        return view('livewire.database-server.index', [
            'servers' => $servers,
        ])->layout('components.layouts.app', ['title' => __('Database Servers')]);
    }
}
