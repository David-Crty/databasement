<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Lazy]
class ConnectionStatus extends Component
{
    use AuthorizesRequests;

    public DatabaseServer $server;

    #[Locked]
    public bool $success = false;

    #[Locked]
    public string $message = '';

    public function mount(DatabaseProvider $provider): void
    {
        $this->authorize('view', $this->server);

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
