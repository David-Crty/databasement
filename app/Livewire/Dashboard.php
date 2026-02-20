<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function poll(): void
    {
        $this->dispatch('refresh-dashboard');
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
