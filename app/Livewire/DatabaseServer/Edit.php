<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Forms\DatabaseServerForm;
use App\Models\DatabaseServer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Edit extends Component
{
    use AuthorizesRequests;

    public DatabaseServerForm $form;

    public function mount(DatabaseServer $server): void
    {
        $this->authorize('update', $server);

        $this->form->setServer($server);
    }

    public function save(): void
    {
        $this->authorize('update', $this->form->server);

        if ($this->form->update()) {
            session()->flash('status', 'Database server updated successfully!');

            $this->redirect(route('database-servers.index'), navigate: true);
        }
    }

    public function testConnection(): void
    {
        $this->form->testConnection();
    }

    public function render(): View
    {
        return view('livewire.database-server.edit')
            ->layout('components.layouts.app', ['title' => __('Edit Database Server')]);
    }
}
