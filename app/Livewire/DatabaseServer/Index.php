<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
    }

    public function cancelDelete()
    {
        $this->deleteId = null;
    }

    public function delete()
    {
        if ($this->deleteId) {
            DatabaseServer::findOrFail($this->deleteId)->delete();
            $this->deleteId = null;

            session()->flash('status', 'Database server deleted successfully!');
        }
    }

    public function render()
    {
        $servers = DatabaseServer::query()
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
