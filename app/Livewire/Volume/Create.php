<?php

namespace App\Livewire\Volume;

use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create Volume')]
class Create extends Component
{
    use AuthorizesRequests;

    public VolumeForm $form;

    public function mount(): void
    {
        $this->authorize('viewForm', Volume::class);
    }

    public function save(): void
    {
        if (Gate::denies('create', Volume::class)) {
            session()->flash('demo_notice', __('Demo mode is enabled. Changes cannot be saved.'));
            $this->redirect(route('volumes.index'), navigate: true);

            return;
        }

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
