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

#[Title('Create Volume')]
class Create extends Component
{
    use AuthorizesRequests, BlocksDemoWrites, Toast;

    public VolumeForm $form;

    public function mount(): void
    {
        $this->authorize('viewForm', Volume::class);
    }

    public function save(): void
    {
        if ($this->blockedForDemo(route('volumes.index'))) {
            return;
        }

        $this->authorize('create', Volume::class);

        $this->form->store();

        $this->success(
            title: __('Volume created successfully!'),
            redirectTo: route('volumes.index')
        );
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
