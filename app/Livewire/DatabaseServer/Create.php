<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\DatabaseServer\Concerns\InteractsWithServerForm;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Traits\BlocksDemoWrites;
use App\Traits\SurfacesValidationErrors;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create Database Server')]
class Create extends Component
{
    use AuthorizesRequests, BlocksDemoWrites, InteractsWithServerForm, SurfacesValidationErrors, Toast;

    public Form $form;

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

        if ($this->withValidationFeedback(fn (): bool => $this->form->store())) {
            $this->success(
                title: __('Database server created successfully!'),
                redirectTo: route('database-servers.index'),
            );
        }
    }

    public function render(): View
    {
        return view('livewire.database-server.create');
    }
}
