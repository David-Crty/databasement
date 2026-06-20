<?php

namespace App\Livewire\DatabaseServer;

use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\TriggerBackupAction;
use App\Traits\OpensAdminerForServer;
use App\Traits\RunsServerBackups;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFacade;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Database Servers')]
class Index extends Component
{
    use AuthorizesRequests, OpensAdminerForServer, RunsServerBackups, Toast, WithPagination;

    #[Url]
    public string $search = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?string $deleteId = null;

    #[Locked]
    public ?string $restoreId = null;

    public bool $showDeleteModal = false;

    public bool $showRedisRestoreModal = false;

    public int $deleteSnapshotCount = 0;

    public bool $keepFiles = false;

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
        $this->success(__('Filters cleared.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-72'],
            ['key' => 'backup', 'label' => __('Backup'), 'sortable' => false, 'class' => 'overflow-hidden hidden sm:table-cell'],
            ['key' => 'jobs', 'label' => __('Jobs'), 'sortable' => false, 'class' => 'w-16 hidden sm:table-cell'],
            ['key' => 'actions', 'label' => null, 'sortable' => false, 'class' => 'w-12'],
        ];
    }

    public function confirmDelete(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('delete', $server);

        $this->deleteId = $id;
        $this->deleteSnapshotCount = $server->snapshots()->count();
        $this->keepFiles = false;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $server = DatabaseServer::findOrFail($this->deleteId);

        $this->authorize('delete', $server);

        $server->skipFileCleanup = $this->keepFiles;
        $server->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success(__('Database server deleted successfully!'));
    }

    public function confirmRestore(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('restore', $server);

        $this->restoreId = $id;

        if ($server->agent_id) {
            $this->error(__('Restore is not yet supported for agent-backed servers.'));

            return;
        }

        if ($server->database_type === DatabaseType::REDIS) {
            $this->showRedisRestoreModal = true;

            return;
        }

        $this->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $id);
    }

    public function openAdminer(string $id): void
    {
        $this->openAdminerForServer(DatabaseServer::findOrFail($id));
    }

    public function runBackup(string $backupId, TriggerBackupAction $action): void
    {
        $backup = Backup::with(['databaseServer', 'volume', 'backupSchedule'])->findOrFail($backupId);

        $this->authorize('backup', $backup->databaseServer);

        try {
            $userId = auth()->id();
            $action->execute($backup, is_int($userId) ? $userId : null);
            $this->success(
                title: __('Backup started successfully!'),
                description: $backup->getDisplayLabel(),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), timeout: 0);
        }
    }

    public function runBackupAll(string $serverId, TriggerBackupAction $action): void
    {
        $server = DatabaseServer::with(['backups.volume', 'backups.backupSchedule'])->findOrFail($serverId);

        $this->authorize('backup', $server);

        $this->triggerAllBackups($server, $action);
    }

    public function toggleBackupsEnabled(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('update', $server);

        $server->update(['backups_enabled' => ! $server->backups_enabled]);

        $this->success(
            title: $server->backups_enabled
                ? __('Backups enabled successfully!')
                : __('Backups disabled successfully!')
        );
    }

    public function render(): View
    {
        $servers = DatabaseServerQuery::buildFromParams(
            search: $this->search,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(10);

        // Share total count globally so it's available inside Mary UI scoped slots
        ViewFacade::share('totalNotificationChannels', NotificationChannel::count());

        return view('livewire.database-server.index', [
            'servers' => $servers,
            'headers' => $this->headers(),
            'canAdminer' => Gate::allows('adminer', DatabaseServer::class),
        ]);
    }
}
