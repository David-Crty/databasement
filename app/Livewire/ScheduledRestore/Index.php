<?php

namespace App\Livewire\ScheduledRestore;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Scheduled Restores')]
class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $enabledFilter = '';

    #[Url]
    public string $sourceServerFilter = '';

    #[Url]
    public string $targetServerFilter = '';

    #[Url]
    public string $dbTypeFilter = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'name', 'direction' => 'asc'];

    /** @var list<string> */
    private const ALLOWED_SORT_COLUMNS = ['name'];

    #[Locked]
    public ?string $deleteScheduledRestoreId = null;

    public bool $showDeleteModal = false;

    #[On('scheduled-restore-saved')]
    public function refreshAfterSave(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingEnabledFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSourceServerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingTargetServerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDbTypeFilter(): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset('search', 'enabledFilter', 'sourceServerFilter', 'targetServerFilter', 'dbTypeFilter');
        $this->resetPage();
        $this->success(__('Filters cleared.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name')],
            ['key' => 'flow', 'label' => __('Source → Target'), 'sortable' => false],
            ['key' => 'backup_schedule', 'label' => __('Schedule'), 'class' => 'w-40', 'sortable' => false],
            ['key' => 'last_run', 'label' => __('Last run'), 'class' => 'w-48', 'sortable' => false],
        ];
    }

    public function openCreate(): void
    {
        $this->authorize('create', ScheduledRestore::class);

        $this->dispatch('open-scheduled-restore-modal');
    }

    public function openEdit(string $id): void
    {
        $scheduledRestore = ScheduledRestore::findOrFail($id);

        $this->authorize('update', $scheduledRestore);

        $this->dispatch('open-scheduled-restore-modal', id: $id);
    }

    public function toggleEnabled(string $id): void
    {
        $scheduledRestore = ScheduledRestore::findOrFail($id);

        $this->authorize('update', $scheduledRestore);

        $scheduledRestore->update(['enabled' => ! $scheduledRestore->enabled]);

        $this->success($scheduledRestore->enabled
            ? __('Scheduled restore enabled.')
            : __('Scheduled restore disabled.'));
    }

    public function runNow(string $id): void
    {
        $scheduledRestore = ScheduledRestore::findOrFail($id);

        $this->authorize('run', $scheduledRestore);

        Artisan::call('restores:run', ['scheduledRestore' => $scheduledRestore->id]);

        $this->success(__('Scheduled restore triggered.'));
    }

    public function confirmDelete(string $id): void
    {
        $scheduledRestore = ScheduledRestore::findOrFail($id);

        $this->authorize('delete', $scheduledRestore);

        $this->deleteScheduledRestoreId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteScheduledRestore(): void
    {
        if (! $this->deleteScheduledRestoreId) {
            return;
        }

        $scheduledRestore = ScheduledRestore::findOrFail($this->deleteScheduledRestoreId);

        $this->authorize('delete', $scheduledRestore);

        $scheduledRestore->delete();
        $this->deleteScheduledRestoreId = null;
        $this->showDeleteModal = false;

        $this->success(__('Scheduled restore deleted.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabledOptions(): array
    {
        return [
            ['id' => '1', 'name' => __('Enabled')],
            ['id' => '0', 'name' => __('Disabled')],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serverOptions(): array
    {
        return DatabaseServer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (DatabaseServer $server) => ['id' => $server->id, 'name' => $server->name])
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dbTypeOptions(): array
    {
        return collect(DatabaseType::cases())
            ->map(fn (DatabaseType $t) => ['id' => $t->value, 'name' => $t->label()])
            ->values()
            ->all();
    }

    public function render(): View
    {
        $sortColumn = in_array($this->sortBy['column'], self::ALLOWED_SORT_COLUMNS, true)
            ? $this->sortBy['column']
            : 'name';

        $query = ScheduledRestore::query()
            ->with(['sourceServer', 'targetServer', 'backupSchedule', 'lastRestore.job'])
            ->whereHas('targetServer')
            ->when($this->search, fn (Builder $q) => $q->where(function (Builder $sq) {
                $sq->whereRaw('name LIKE ?', ['%'.$this->search.'%'])
                    ->orWhereRaw('schema_name LIKE ?', ['%'.$this->search.'%']);
            }))
            ->when($this->enabledFilter !== '', fn (Builder $q) => $q->where('enabled', (bool) $this->enabledFilter))
            ->when($this->sourceServerFilter, fn (Builder $q) => $q->where('source_server_id', $this->sourceServerFilter))
            ->when($this->targetServerFilter, fn (Builder $q) => $q->where('target_server_id', $this->targetServerFilter))
            ->when($this->dbTypeFilter, fn (Builder $q) => $q->whereHas('targetServer', fn (Builder $sq) => $sq->whereRaw('database_type = ?', [$this->dbTypeFilter])))
            ->orderBy($sortColumn, $this->sortBy['direction'] === 'desc' ? 'desc' : 'asc');

        return view('livewire.scheduled-restore.index', [
            'scheduledRestores' => $query->paginate(15),
            'headers' => $this->headers(),
            'enabledOptions' => $this->enabledOptions(),
            'serverOptions' => $this->serverOptions(),
            'dbTypeOptions' => $this->dbTypeOptions(),
        ]);
    }
}
