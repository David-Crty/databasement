<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class Appearance extends Component
{
    public function render()
    {
        return view('livewire.settings.appearance')
            ->layout('components.layouts.app', ['title' => __('Appearance')]);
    }
}
