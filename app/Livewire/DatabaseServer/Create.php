<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Forms\DatabaseServerForm;
use App\Models\DatabaseServer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public DatabaseServerForm $form;

    public function mount(): void
    {
        $this->authorize('create', DatabaseServer::class);
    }

    public function save(): void
    {
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

    public function render(): View
    {
        return view('livewire.database-server.create')
            ->layout('components.layouts.app', ['title' => __('Create Database Server')]);
    }
}
