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

        if ($this->server->agent_id) {
            $agent = $this->server->agent;
            $this->success = $agent !== null && $agent->isOnline();
            $this->message = $this->success
                ? __('Agent online')
                : __('Agent offline');

            return;
        }

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
