<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Concerns\HandlesDemoMode;
use App\Livewire\Forms\DatabaseServerForm;
use App\Models\DatabaseServer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Mary\Traits\Toast;

class Edit extends Component
{
    use AuthorizesRequests;
    use HandlesDemoMode;
    use Toast;

    public DatabaseServerForm $form;

    public function mount(DatabaseServer $server): void
    {
        $this->authorize('update', $server);

        $this->form->setServer($server);
    }

    public function save(): void
    {
        if ($this->abortIfDemoMode('database-servers.index')) {
            return;
        }

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

    public function refreshVolumes(): void
    {
        $this->success(__('Volume list refreshed.'), position: 'toast-bottom');
    }

    public function render(): View
    {
        return view('livewire.database-server.edit')
            ->layout('components.layouts.app', ['title' => __('Edit Database Server')]);
    }
}
