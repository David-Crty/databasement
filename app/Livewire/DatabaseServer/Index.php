<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $drawer = false;

    #[Locked]
    public ?string $deleteId = null;

    #[Locked]
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
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('delete', $server);

        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete()
    {
        if (! $this->deleteId) {
            return;
        }

        $server = DatabaseServer::findOrFail($this->deleteId);

        $this->authorize('delete', $server);

        $server->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        session()->flash('status', 'Database server deleted successfully!');
    }

    public function confirmRestore(string $id)
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('restore', $server);

        $this->restoreId = $id;
        $this->dispatch('open-restore-modal', targetServerId: $id);
    }

    public function runBackup(string $id, TriggerBackupAction $action)
    {
        $server = DatabaseServer::with(['backup.volume'])->findOrFail($id);

        $this->authorize('backup', $server);

        try {
            $result = $action->execute($server, auth()->id());
            $this->success($result['message'], position: 'toast-bottom');
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), position: 'toast-bottom');
        }
    }

    public function render()
    {
        $servers = DatabaseServerQuery::buildFromParams(
            search: $this->search,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(10);

        return view('livewire.database-server.index', [
            'servers' => $servers,
            'headers' => $this->headers(),
        ])->layout('components.layouts.app', ['title' => __('Database Servers')]);
    }
}
