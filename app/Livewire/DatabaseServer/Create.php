<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Forms\DatabaseServerForm;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Traits\BlocksDemoWrites;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create Database Server')]
class Create extends Component
{
    use AuthorizesRequests, BlocksDemoWrites, Toast;

    public DatabaseServerForm $form;

    public function mount(): void
    {
        $this->authorize('viewForm', DatabaseServer::class);

        $dailyScheduleId = BackupSchedule::where('name', 'Daily')->value('id');
        $this->form->addBackup($dailyScheduleId);
    }

    public function save(): void
    {
        if ($this->blockedForDemo(route('database-servers.index'))) {
            return;
        }

        $this->authorize('create', DatabaseServer::class);

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

    public function generateSshKey(): void
    {
        $this->form->generateSshKey();
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
