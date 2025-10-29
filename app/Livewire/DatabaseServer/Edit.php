<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Edit extends Component
{
    public DatabaseServer $server;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255')]
    public string $host = '';

    #[Validate('required|integer|min:1|max:65535')]
    public int $port = 3306;

    #[Validate('required|string|in:mysql,postgresql,mariadb,sqlite')]
    public string $database_type = 'mysql';

    #[Validate('required|string|max:255')]
    public string $username = '';

    #[Validate('nullable|string|max:255')]
    public ?string $password = null;

    #[Validate('nullable|string|max:255')]
    public ?string $database_name = null;

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    public function mount(DatabaseServer $server)
    {
        $this->server = $server;
        $this->name = $server->name;
        $this->host = $server->host;
        $this->port = $server->port;
        $this->database_type = $server->database_type;
        $this->username = $server->username;
        $this->database_name = $server->database_name;
        $this->description = $server->description;
    }

    public function update()
    {
        $validated = $this->validate();

        // Only update password if a new one is provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $this->server->update($validated);

        session()->flash('status', 'Database server updated successfully!');

        return $this->redirect(route('database-servers.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.database-server.edit')
            ->layout('components.layouts.app', ['title' => __('Edit Database Server')]);
    }
}
