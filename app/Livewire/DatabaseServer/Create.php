<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Concerns\HandlesDemoMode;
use App\Livewire\Forms\DatabaseServerForm;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

#[Title('Create Database Server')]
class Create extends Component
{
    use AuthorizesRequests;
    use HandlesDemoMode;
    use Toast;

    public DatabaseServerForm $form;

    public function mount(): void
    {
        $this->authorize('create', DatabaseServer::class);

        $dailySchedule = BackupSchedule::where('name', 'Daily')->first();
        if ($dailySchedule) {
            $this->form->backup_schedule_id = $dailySchedule->id;
        }
    }

    public function save(): void
    {
        if ($this->abortIfDemoMode('database-servers.index')) {
            return;
        }

        $this->authorize('create', DatabaseServer::class);

        if ($this->form->store()) {
            session()->flash('status', 'Database server created successfully!');

            $this->redirect(route('database-servers.index'), navigate: true);
        }
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
        $this->success(__('Volume list refreshed.'), position: 'toast-bottom');
    }

    public function refreshSchedules(): void
    {
        $this->success(__('Schedule list refreshed.'), position: 'toast-bottom');
    }

    public function render(): View
    {
        return view('livewire.database-server.create');
    }
}
