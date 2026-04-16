<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Forms\DatabaseServerForm;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create Database Server')]
class Create extends Component
{
    use AuthorizesRequests, Toast;

    public DatabaseServerForm $form;

    public function mount(): void
    {
        $this->authorize('viewForm', DatabaseServer::class);

        $dailyScheduleId = BackupSchedule::where('name', 'Daily')->value('id');
        $this->form->addBackup($dailyScheduleId);
    }

    public function save(): void
    {
        if (Gate::denies('create', DatabaseServer::class)) {
            $this->warning(
                title: __('Demo mode is enabled. Changes cannot be saved.'),
                redirectTo: route('database-servers.index'),
                flashAs: 'demo_notice',
            );

            return;
        }

        if ($this->form->store()) {
            $this->success(
                title: __('Database server created successfully!'),
                redirectTo: route('database-servers.index'),
            );
        }
    }

    public function addBackup(?string $defaultScheduleId = null): void
    {
        $this->form->addBackup($defaultScheduleId);
    }

    public function removeBackup(int $index): void
    {
        $this->form->removeBackup($index);
    }

    public function addDatabasePath(int $backupIndex): void
    {
        $this->form->addDatabasePath($backupIndex);
    }

    public function removeDatabasePath(int $backupIndex, int $pathIndex): void
    {
        $this->form->removeDatabasePath($backupIndex, $pathIndex);
    }

    public function testConnection(): void
    {
        $this->form->testConnection();
    }

    public function testSshConnection(): void
    {
        $this->form->testSshConnection();
    }

    public function refreshVolumes(): void
    {
        $this->success(__('Volume list refreshed.'));
    }

    public function refreshSchedules(): void
    {
        $this->success(__('Schedule list refreshed.'));
    }

    public function toggleNotificationChannel(string $channelId): void
    {
        $this->form->toggleNotificationChannel($channelId);
    }

    public function render(): View
    {
        return view('livewire.database-server.create');
    }
}
