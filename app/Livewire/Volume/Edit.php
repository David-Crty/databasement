<?php

namespace App\Livewire\Volume;

use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use App\Traits\BlocksDemoWrites;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Volume')]
class Edit extends Component
{
    use AuthorizesRequests, BlocksDemoWrites, Toast;

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
        if ($this->blockedForDemo(route('volumes.index'))) {
            return;
        }

        $this->authorize('update', $this->form->volume);

        if ($this->hasSnapshots) {
            $this->form->updateNameOnly();
        } else {
            $this->form->update();
        }

        $this->success(
            title: __('Volume updated successfully!'),
            redirectTo: route('volumes.index')
        );
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
