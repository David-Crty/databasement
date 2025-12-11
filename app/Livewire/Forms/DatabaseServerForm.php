<?php

namespace App\Livewire\Forms;

use App\Facades\DatabaseConnectionTester;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Services\Backup\DatabaseListService;
use Livewire\Attributes\Validate;
use Livewire\Form;

class DatabaseServerForm extends Form
{
    public ?DatabaseServer $server = null;

    public string $name = '';

    public string $host = '';

    public int $port = 3306;

    public string $database_type = 'mysql';

    public string $username = '';

    public string $password = '';

    public ?string $database_name = null;

    public bool $backup_all_databases = false;

    public ?string $description = null;

    public string $volume_id = '';

    public string $recurrence = 'daily';

    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    public bool $testingConnection = false;

    /** @var array<array{id: string, name: string}> */
    public array $availableDatabases = [];

    public bool $loadingDatabases = false;

    public function setServer(DatabaseServer $server): void
    {
        $this->server = $server;
        $this->name = $server->name;
        $this->host = $server->host;
        $this->port = $server->port;
        $this->database_type = $server->database_type;
        $this->username = $server->username;
        $this->database_name = $server->database_name;
        $this->backup_all_databases = $server->backup_all_databases;
        $this->description = $server->description;
        // Don't populate password for security
        $this->password = '';

        // Load backup data if exists
        if ($server->backup) {
            /** @var \App\Models\Backup $backup */
            $backup = $server->backup;
            $this->volume_id = $backup->volume_id;
            $this->recurrence = $backup->recurrence;
        }

        // Mark connection as already tested for existing servers
        // and load available databases
        $this->connectionTestSuccess = true;
        $this->connectionTestMessage = __('Connection verified');
        $this->loadAvailableDatabases();
    }

    /**
     * @return array<string, mixed>
     */
    public function formValidate(): array
    {
        return $this->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'database_type' => 'required|string|in:mysql,postgresql,mariadb,sqlite',
            'username' => 'required|string|max:255',
            'password' => 'nullable',
            'backup_all_databases' => 'boolean',
            'database_name' => $this->backup_all_databases ? 'nullable|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'volume_id' => 'required|exists:volumes,id',
            'recurrence' => 'required|string|in:'.implode(',', Backup::RECURRENCE_TYPES),
        ]);
    }

    public function store(): bool
    {
        $validated = $this->formValidate();

        $this->testConnection();
        if (! $this->connectionTestSuccess) {
            return false;
        }

        // Extract backup data
        $backupData = [
            'volume_id' => $validated['volume_id'],
            'recurrence' => $validated['recurrence'],
        ];
        unset($validated['volume_id'], $validated['recurrence']);

        // Create database server
        $server = DatabaseServer::create($validated);

        // Create backup
        $server->backup()->create($backupData);

        return true;
    }

    public function update(): bool
    {
        $validated = $this->formValidate();

        $this->testConnection();
        if (! $this->connectionTestSuccess) {
            return false;
        }

        // Extract backup data
        $backupData = [
            'volume_id' => $validated['volume_id'],
            'recurrence' => $validated['recurrence'],
        ];
        unset($validated['volume_id'], $validated['recurrence']);

        // Only update password if a new one is provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $this->server->update($validated);

        // Update or create backup
        if ($this->server->backup) {
            $this->server->backup()->update($backupData);
        } else {
            $this->server->backup()->create($backupData);
        }

        return true;
    }

    public function testConnection(): void
    {
        $this->testingConnection = true;
        $this->connectionTestMessage = null;
        $this->availableDatabases = [];

        // Validate only the connection-related fields
        try {
            $this->validate([
                'host' => 'required|string|max:255',
                'port' => 'required|integer|min:1|max:65535',
                'database_type' => 'required|string|in:mysql,postgresql,mariadb,sqlite',
                'username' => 'required|string|max:255',
                'password' => (empty($this->server) ? 'required|string|max:255' : 'nullable'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            $this->connectionTestMessage = 'Please fill in all required connection fields.';

            return;
        }

        // Test connection without specific database (server-level connection)
        $result = DatabaseConnectionTester::test([
            'database_type' => $this->database_type,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => ($this->password) ?: $this->server?->password,
            'database_name' => null,
        ]);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->testingConnection = false;

        // If connection successful, load available databases
        if ($this->connectionTestSuccess) {
            $this->loadAvailableDatabases();
        }
    }

    /**
     * Load available databases from the server for selection
     */
    public function loadAvailableDatabases(): void
    {
        $this->loadingDatabases = true;
        $this->availableDatabases = [];

        try {
            // Create a temporary DatabaseServer object for the service
            $tempServer = new DatabaseServer([
                'host' => $this->host,
                'port' => $this->port,
                'database_type' => $this->database_type,
                'username' => $this->username,
                'password' => ($this->password) ?: $this->server?->password,
            ]);

            $databaseListService = app(DatabaseListService::class);
            $databases = $databaseListService->listDatabases($tempServer);

            // Format for select options
            $this->availableDatabases = collect($databases)
                ->map(fn (string $db) => ['id' => $db, 'name' => $db])
                ->toArray();
        } catch (\Exception $e) {
            // If we can't list databases, the user can still type manually
            $this->availableDatabases = [];
        }

        $this->loadingDatabases = false;
    }
}
