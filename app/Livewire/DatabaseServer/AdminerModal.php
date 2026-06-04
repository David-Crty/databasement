<?php

namespace App\Livewire\DatabaseServer;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class AdminerModal extends Component
{
    public bool $showModal = false;

    public string $serverName = '';

    public string $databaseIcon = '';

    public string $databaseType = '';

    #[Locked]
    public string $adminerUrl = '';

    #[On('open-adminer-modal')]
    public function openModal(string $serverName, string $databaseIcon, string $databaseType, string $adminerUrl): void
    {
        $this->serverName = $serverName;
        $this->databaseIcon = $databaseIcon;
        $this->databaseType = $databaseType;
        $this->adminerUrl = $adminerUrl;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->adminerUrl = '';
    }

    public function render(): View
    {
        return view('livewire.database-server.adminer-modal');
    }
}
