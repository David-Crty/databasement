<?php

namespace App\Livewire\Forms;

use App\Facades\DatabaseConnectionTester;
use App\Models\Backup;
use App\Models\DatabaseServer;
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

    public ?string $description = null;

    public string $volume_id = '';

    public string $recurrence = 'daily';

    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    public bool $testingConnection = false;

    public function setServer(DatabaseServer $server): void
    {
        $this->server = $server;
        $this->name = $server->name;
        $this->host = $server->host;
        $this->port = $server->port;
        $this->database_type = $server->database_type;
        $this->username = $server->username;
        $this->database_name = $server->database_name;
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
            'database_name' => 'required|string|max:255',
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

        $result = DatabaseConnectionTester::test([
            'database_type' => $this->database_type,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => ($this->password) ?: $this->server->password,
            'database_name' => $this->database_name,
        ]);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->testingConnection = false;
    }
}
