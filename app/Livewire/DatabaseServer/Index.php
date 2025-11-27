<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use App\Services\Backup\BackupTask;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class Index extends Component
{
    use Toast, WithPagination;

    public string $search = '';

    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $drawer = false;

    public ?string $deleteId = null;

    public ?string $restoreId = null;

    public bool $showDeleteModal = false;

    public function updatingSearch()
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
        $this->reset('search');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-64'],
            ['key' => 'database_type', 'label' => __('Type'), 'class' => 'w-32'],
            ['key' => 'host', 'label' => __('Host'), 'class' => 'w-48'],
            ['key' => 'database_name', 'label' => __('Database'), 'sortable' => false],
            ['key' => 'backup', 'label' => __('Backup'), 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-40'],
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
                $this->error('No backup configuration found for this database server.', position: 'toast-bottom');

                return;
            }

            $snapshot = $backupTask->run($server, 'manual', auth()->id());

            $this->success("Backup completed successfully! Snapshot ID: {$snapshot->id}", position: 'toast-bottom');
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage(), position: 'toast-bottom');
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
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(10);

        return view('livewire.database-server.index', [
            'servers' => $servers,
            'headers' => $this->headers(),
        ])->layout('components.layouts.app', ['title' => __('Database Servers')]);
    }
}
