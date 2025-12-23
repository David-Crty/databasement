<?php

namespace App\Livewire\Volume;

use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public VolumeForm $form;

    public function mount(): void
    {
        $this->authorize('create', Volume::class);
    }

    public function save(): void
    {
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
        return view('livewire.volume.create')
            ->layout('components.layouts.app', ['title' => __('Create Volume')]);
    }
}
