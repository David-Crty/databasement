<?php

namespace App\Livewire\DatabaseServer;

use App\Enums\DatabaseType;
use App\Enums\NotificationChannelSelection;
use App\Enums\NotificationTrigger;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Services\Backup\TriggerBackupAction;
use App\Traits\OpensAdminerForServer;
use App\Traits\RunsServerBackups;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Database Server')]
class Show extends Component
{
    use AuthorizesRequests, OpensAdminerForServer, RunsServerBackups, Toast;

    public DatabaseServer $server;

    public int $snapshotsCount = 0;

    public int $restoresCount = 0;

    public bool $showDeleteModal = false;

    public bool $showRedisRestoreModal = false;

    public int $deleteSnapshotCount = 0;

    public bool $keepFiles = false;

    public function mount(DatabaseServer $server): void
    {
        $this->authorize('view', $server);

        $server->load([
            'sshConfig',
            'agent',
            'backups.volume',
            'backups.backupSchedule',
            'notificationChannels',
        ]);

        $this->server = $server;
        $this->snapshotsCount = $server->snapshots()->count();
        $this->restoresCount = Restore::where('target_server_id', $server->id)->count();
    }

    public function runBackupAll(TriggerBackupAction $action): void
    {
        $this->authorize('backup', $this->server);

        $this->server->load(['backups.volume', 'backups.backupSchedule']);

        $this->triggerAllBackups($this->server, $action);
    }

    public function confirmRestore(): void
    {
        $this->authorize('restore', $this->server);

        if ($this->server->agent_id) {
            $this->error(__('Restore is not yet supported for agent-backed servers.'));

            return;
        }

        if ($this->server->database_type === DatabaseType::REDIS) {
            $this->showRedisRestoreModal = true;

            return;
        }

        $this->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $this->server->id);
    }

    public function openAdminer(): void
    {
        $this->openAdminerForServer($this->server);
    }

    public function confirmDelete(): void
    {
        $this->authorize('delete', $this->server);

        $this->deleteSnapshotCount = $this->server->snapshots()->count();
        $this->keepFiles = false;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->server);

        $this->server->skipFileCleanup = $this->keepFiles;
        $this->server->delete();
        $this->showDeleteModal = false;

        $this->success(
            title: __('Database server deleted successfully!'),
            redirectTo: route('database-servers.index'),
        );
    }

    /**
     * Notification channels actually delivered to for this server. Empty when
     * the trigger is disabled.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function activeChannels(): Collection
    {
        if ($this->server->notification_trigger === NotificationTrigger::None) {
            return new Collection;
        }

        return $this->server->notification_channel_selection === NotificationChannelSelection::All
            ? NotificationChannel::orderBy('name')->get()
            : $this->server->notificationChannels;
    }

    public function render(): View
    {
        return view('livewire.database-server.show', [
            'activeChannels' => $this->activeChannels(),
            'canAdminer' => Gate::allows('adminer', DatabaseServer::class),
        ]);
    }
}
