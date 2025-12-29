<?php

namespace App\Livewire\Forms;

use App\Exceptions\Backup\EncryptionException;
use App\Facades\DatabaseConnectionTester;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Services\Backup\DatabaseListService;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class DatabaseServerForm extends Form
{
    public ?DatabaseServer $server = null;

    public string $name = '';

    public string $host = '';

    public int $port = 3306;

    public string $database_type = 'mysql';

    public string $sqlite_path = '';

    public string $username = '';

    public string $password = '';

    /** @var array<string> */
    public array $database_names = [];

    /** @var string Input field for manual database entry (comma-separated) */
    public string $database_names_input = '';

    public bool $backup_all_databases = false;

    public ?string $description = null;

    public string $volume_id = '';

    public string $recurrence = 'daily';

    public ?int $retention_days = 14;

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
        $this->host = $server->host ?? '';
        $this->port = $server->port ?? 3306;
        $this->database_type = $server->database_type;
        $this->sqlite_path = $server->sqlite_path ?? '';
        $this->username = $server->username ?? '';
        $this->database_names = $server->database_names ?? [];
        $this->database_names_input = implode(', ', $this->database_names);
        $this->backup_all_databases = $server->backup_all_databases ?? false;
        $this->description = $server->description;
        // Don't populate password for security
        $this->password = '';

        // Load backup data if exists
        if ($server->backup) {
            /** @var Backup $backup */
            $backup = $server->backup;
            $this->volume_id = $backup->volume_id;
            $this->recurrence = $backup->recurrence;
            $this->retention_days = $backup->retention_days;
        }
    }

    /**
     * Normalize database_names from either multiselect or comma-separated input
     */
    public function normalizeDatabaseNames(): void
    {
        // If multiselect is used (availableDatabases is populated), use database_names directly
        if (! empty($this->availableDatabases)) {
            return;
        }

        // Otherwise, parse from comma-separated input
        if (! empty($this->database_names_input)) {
            $this->database_names = array_values(array_filter(
                array_map('trim', explode(',', $this->database_names_input))
            ));
        }
    }

    /**
     * Check if current database type is SQLite
     */
    public function isSqlite(): bool
    {
        return $this->database_type === 'sqlite';
    }

    /**
     * @return array<string, mixed>
     */
    public function formValidate(): array
    {
        $this->normalizeDatabaseNames();

        $rules = [
            'name' => 'required|string|max:255',
            'database_type' => 'required|string|in:mysql,postgresql,mariadb,sqlite',
            'description' => 'nullable|string|max:1000',
            'volume_id' => 'required|exists:volumes,id',
            'recurrence' => 'required|string|in:'.implode(',', Backup::RECURRENCE_TYPES),
            'retention_days' => 'nullable|integer|min:1|max:35',
        ];

        if ($this->isSqlite()) {
            // SQLite only needs path
            $rules['sqlite_path'] = 'required|string|max:1000';
        } else {
            // Client-server databases need connection details
            $rules['host'] = 'required|string|max:255';
            $rules['port'] = 'required|integer|min:1|max:65535';
            $rules['username'] = 'required|string|max:255';
            $rules['password'] = 'nullable';
            $rules['backup_all_databases'] = 'boolean';
            $rules['database_names'] = $this->backup_all_databases ? 'nullable|array' : 'required|array|min:1';
            $rules['database_names.*'] = 'string|max:255';
        }

        return $this->validate($rules);
    }

    public function store(): bool
    {
        $validated = $this->formValidate();

        $this->testConnection();
        if (! $this->connectionTestSuccess) {
            return false;
        }

        // Extract backup data
        /** @var array{volume_id: string, recurrence: string, retention_days: int|null} $backupData */
        $backupData = [
            'volume_id' => $validated['volume_id'],
            'recurrence' => $validated['recurrence'],
            'retention_days' => $validated['retention_days'] ?? null,
        ];
        unset($validated['volume_id'], $validated['recurrence'], $validated['retention_days']);

        // Create database server
        $server = DatabaseServer::create($validated);

        // Create backup
        /** @var Backup $backup */
        $backup = $server->backup()->create($backupData);

        return true;
    }

    public function update(): bool
    {
        // If the stored password can't be decrypted (APP_KEY changed), clear it first
        try {
            $this->server->getDecryptedPassword();
        } catch (EncryptionException) {
            DatabaseServer::where('id', $this->server->id)->update(['password' => null]);
            $this->server->refresh();
        }

        $validated = $this->formValidate();

        // Extract backup data
        /** @var array{volume_id: string, recurrence: string, retention_days: int|null} $backupData */
        $backupData = [
            'volume_id' => $validated['volume_id'],
            'recurrence' => $validated['recurrence'],
            'retention_days' => $validated['retention_days'] ?? null,
        ];
        unset($validated['volume_id'], $validated['recurrence'], $validated['retention_days']);

        // Only update password if a new one is provided
        if (isset($validated['password']) && empty($validated['password'])) {
            unset($validated['password']);
        }
        if (! empty($validated['backup_all_databases'])) {
            $validated['database_names'] = null;
        }

        $this->server->update($validated);

        if ($this->server->backup()->exists()) {
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
            if ($this->isSqlite()) {
                $this->validate([
                    'sqlite_path' => 'required|string|max:1000',
                ]);
            } else {
                $this->validate([
                    'host' => 'required|string|max:255',
                    'port' => 'required|integer|min:1|max:65535',
                    'database_type' => 'required|string|in:mysql,postgresql,mariadb,sqlite',
                    'username' => 'required|string|max:255',
                    'password' => (empty($this->server) ? 'required|string|max:255' : 'nullable'),
                ]);
            }
        } catch (ValidationException $e) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            $this->connectionTestMessage = 'Please fill in all required connection fields.';

            return;
        }

        // Test connection
        try {
            $password = ($this->password) ?: $this->server?->getDecryptedPassword();
        } catch (EncryptionException $e) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            $this->connectionTestMessage = $e->getMessage();

            return;
        }

        $result = DatabaseConnectionTester::test([
            'database_type' => $this->database_type,
            'host' => $this->isSqlite() ? $this->sqlite_path : $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $password,
            'database_name' => null,
        ]);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->testingConnection = false;

        // If connection successful and not SQLite, load available databases
        if ($this->connectionTestSuccess && ! $this->isSqlite()) {
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
            $password = ($this->password) ?: $this->server?->getDecryptedPassword();

            // Create a temporary DatabaseServer object for the service
            $tempServer = new DatabaseServer([
                'host' => $this->host,
                'port' => $this->port,
                'database_type' => $this->database_type,
                'username' => $this->username,
                'password' => $password,
            ]);

            $databaseListService = app(DatabaseListService::class);
            $databases = $databaseListService->listDatabases($tempServer);

            // Format for select options
            $this->availableDatabases = collect($databases)
                ->map(fn (string $db) => ['id' => $db, 'name' => $db])
                ->toArray();
        } catch (\Exception) {
            // If we can't list databases (encryption error, connection error, etc.),
            // the user can still type manually
            $this->availableDatabases = [];
        }

        $this->loadingDatabases = false;
    }
}
