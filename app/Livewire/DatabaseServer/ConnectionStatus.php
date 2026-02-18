<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class ConnectionStatus extends Component
{
    public DatabaseServer $server;

    public bool $success = false;

    public string $message = '';

    public function mount(DatabaseProvider $provider): void
    {
        $result = $provider->testConnectionForServer($this->server);

        $this->success = $result['success'];
        $this->message = $result['message'];
    }

    public function placeholder(): View
    {
        return view('livewire.database-server.connection-status', [
            'loading' => true,
            'success' => false,
            'message' => __('Checking connection...'),
        ]);
    }

    public function render(): View
    {
        return view('livewire.database-server.connection-status', [
            'loading' => false,
        ]);
    }
}
