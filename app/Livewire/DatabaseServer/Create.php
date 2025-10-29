<?php

namespace App\Livewire\DatabaseServer;

use App\Models\DatabaseServer;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Create extends Component
{
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

    #[Validate('required|string|max:255')]
    public string $password = '';

    #[Validate('nullable|string|max:255')]
    public ?string $database_name = null;

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    public function save()
    {
        $validated = $this->validate();

        DatabaseServer::create($validated);

        session()->flash('status', 'Database server created successfully!');

        return $this->redirect(route('database-servers.create'), navigate: true);
    }

    public function render()
    {
        return view('livewire.database-server.create');
    }
}
