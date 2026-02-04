<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance')]
class Appearance extends Component
{
    public function render(): View
    {
        return view('livewire.settings.appearance');
    }
}
