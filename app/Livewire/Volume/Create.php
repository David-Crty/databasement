<?php

namespace App\Livewire\Volume;

use App\Livewire\Forms\VolumeForm;
use Livewire\Component;

class Create extends Component
{
    public VolumeForm $form;

    public function save()
    {
        $this->form->store();

        session()->flash('status', 'Volume created successfully!');

        return $this->redirect(route('volumes.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.volume.create')
            ->layout('components.layouts.app', ['title' => __('Create Volume')]);
    }
}
