<?php

namespace App\Livewire\Volume;

use App\Livewire\Forms\VolumeForm;
use App\Models\Volume;
use Livewire\Component;

class Edit extends Component
{
    public VolumeForm $form;

    public function mount(Volume $volume)
    {
        $this->form->setVolume($volume);
    }

    public function save()
    {
        $this->form->update();

        session()->flash('status', 'Volume updated successfully!');

        return $this->redirect(route('volumes.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.volume.edit')
            ->layout('components.layouts.app', ['title' => __('Edit Volume')]);
    }
}
