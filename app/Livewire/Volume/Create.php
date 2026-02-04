<?php

namespace App\Livewire\Volume;

use App\Livewire\Concerns\HandlesDemoMode;
use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create Volume')]
class Create extends Component
{
    use AuthorizesRequests;
    use HandlesDemoMode;

    public VolumeForm $form;

    public function mount(): void
    {
        $this->authorize('create', Volume::class);
    }

    public function save(): void
    {
        if ($this->abortIfDemoMode('volumes.index')) {
            return;
        }

        $this->authorize('create', Volume::class);

        $this->form->store();

        session()->flash('status', 'Volume created successfully!');

        $this->redirect(route('volumes.index'), navigate: true);
    }

    public function testConnection(): void
    {
        $this->form->testConnection();
    }

    public function render(): View
    {
        return view('livewire.volume.create');
    }
}
