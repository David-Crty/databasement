<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Contracts\View\View;
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

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?string $deleteId = null;

    #[Locked]
    public ?string $restoreId = null;

    public bool $showDeleteModal = false;

    public int $deleteSnapshotCount = 0;

    public function updatingSearch(): void
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
        $this->reset('search');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-64'],
            ['key' => 'host', 'label' => __('Connection'), 'class' => 'w-48'],
            ['key' => 'database_names', 'label' => __('Databases'), 'sortable' => false],
            ['key' => 'backup', 'label' => __('Backup'), 'sortable' => false],
            ['key' => 'jobs', 'label' => __('Jobs'), 'sortable' => false, 'class' => 'w-32'],
        ];
    }

    public function confirmDelete(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('delete', $server);

        $this->deleteId = $id;
        $this->deleteSnapshotCount = $server->snapshots()->count();
        $this->showDeleteModal = true;
    }

    public function delete(): void
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

    public function confirmRestore(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('restore', $server);

        $this->restoreId = $id;
        $this->dispatch('open-restore-modal', targetServerId: $id);
    }

    public function runBackup(string $id, TriggerBackupAction $action): void
    {
        $server = DatabaseServer::with(['backup.volume'])->findOrFail($id);

        $this->authorize('backup', $server);

        try {
            $userId = auth()->id();
            $result = $action->execute($server, is_int($userId) ? $userId : null);
            $this->success($result['message'], position: 'toast-bottom');
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), position: 'toast-bottom');
        }
    }

    public function render(): View
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
