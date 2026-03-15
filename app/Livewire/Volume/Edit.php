<?php

namespace App\Livewire\Volume;

use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Volume')]
class Edit extends Component
{
    use AuthorizesRequests;

    public VolumeForm $form;

    public bool $hasSnapshots = false;

    public function mount(Volume $volume): void
    {
        $this->authorize('viewForm', $volume);

        $this->hasSnapshots = $volume->hasSnapshots();
        $this->form->setVolume($volume);
    }

    public function save(): void
    {
        if (Gate::denies('update', $this->form->volume)) {
            session()->flash('demo_notice', __('Demo mode is enabled. Changes cannot be saved.'));
            $this->redirect(route('volumes.index'), navigate: true);

            return;
        }

        if ($this->hasSnapshots) {
            $this->form->updateNameOnly();
        } else {
            $this->form->update();
        }

        session()->flash('status', 'Volume updated successfully!');

        $this->redirect(route('volumes.index'), navigate: true);
    }

    public function testConnection(): void
    {
        $this->form->testConnection();
    }

    public function render(): View
    {
        return view('livewire.volume.edit');
    }
}
