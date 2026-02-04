<?php

namespace App\Livewire\Volume;

use App\Livewire\Concerns\HandlesDemoMode;
use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Volume')]
class Edit extends Component
{
    use AuthorizesRequests;
    use HandlesDemoMode;

    public VolumeForm $form;

    public bool $hasSnapshots = false;

    public function mount(Volume $volume): void
    {
        $this->authorize('update', $volume);

        $this->hasSnapshots = $volume->hasSnapshots();
        $this->form->setVolume($volume);
    }

    public function save(): void
    {
        if ($this->abortIfDemoMode('volumes.index')) {
            return;
        }

        $this->authorize('update', $this->form->volume);

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
