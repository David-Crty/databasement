<?php

namespace App\Livewire\Forms;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\EncryptionException;
use App\Facades\DatabaseConnectionTester;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Rules\SafePath;
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

    public bool $backups_enabled = true;

    public string $volume_id = '';

    public string $path = '';

    public string $recurrence = 'daily';

    public ?int $retention_days = 14;

    public string $retention_policy = Backup::RETENTION_DAYS;

    public ?int $gfs_keep_daily = 7;

    public ?int $gfs_keep_weekly = 4;

    public ?int $gfs_keep_monthly = 12;

    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    public bool $testingConnection = false;

    /** @var array<string, mixed> Connection test details (dbms, ping, ssl, etc.) */
    public array $connectionTestDetails = [];

    public bool $showConnectionDetails = false;

    /** @var array<array{id: string, name: string}> */
    public array $availableDatabases = [];

    public bool $loadingDatabases = false;

    /**
     * Called when retention_policy changes - set default values if switching
     * to a policy and its values are empty.
     */
    public function updatedRetentionPolicy(string $value): void
    {
        if ($value === Backup::RETENTION_DAYS && empty($this->retention_days)) {
            $this->retention_days = 14;
        }

        if ($value === Backup::RETENTION_GFS
            && empty($this->gfs_keep_daily)
            && empty($this->gfs_keep_weekly)
            && empty($this->gfs_keep_monthly)
        ) {
            $this->gfs_keep_daily = 7;
            $this->gfs_keep_weekly = 4;
            $this->gfs_keep_monthly = 12;
        }
    }

    /**
     * Called when database_type changes - update port to the default for that type
     * if the current port is a known default port.
     */
    public function updatedDatabaseType(string $value): void
    {
        $defaultPorts = array_map(
            fn (DatabaseType $type) => $type->defaultPort(),
            DatabaseType::cases()
        );

        // Only auto-update if current port is one of the known defaults
        if (in_array($this->port, $defaultPorts, true)) {
            $newType = DatabaseType::tryFrom($value);
            if ($newType) {
                $this->port = $newType->defaultPort();
            }
        }

        // Reset connection test when type changes
        $this->connectionTestSuccess = false;
        $this->connectionTestMessage = null;
        $this->availableDatabases = [];
    }

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
        $this->backups_enabled = $server->backups_enabled ?? true;
        // Don't populate password for security
        $this->password = '';

        // Load backup data if exists
        if ($server->backup) {
            /** @var Backup $backup */
            $backup = $server->backup;
            $this->volume_id = $backup->volume_id;
            $this->path = $backup->path ?? '';
            $this->recurrence = $backup->recurrence;
            $this->retention_days = $backup->retention_days;
            $this->retention_policy = $backup->retention_policy ?? Backup::RETENTION_DAYS;
            $this->gfs_keep_daily = $backup->gfs_keep_daily;
            $this->gfs_keep_weekly = $backup->gfs_keep_weekly;
            $this->gfs_keep_monthly = $backup->gfs_keep_monthly;
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
     * Get database type options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getDatabaseTypeOptions(): array
    {
        return DatabaseType::toSelectOptions();
    }

    /**
     * Get recurrence options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getRecurrenceOptions(): array
    {
        return collect(Backup::RECURRENCE_TYPES)->map(fn ($type) => [
            'id' => $type,
            'name' => __(ucfirst($type)),
        ])->toArray();
    }

    /**
     * Get retention policy options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getRetentionPolicyOptions(): array
    {
        return [
            ['id' => Backup::RETENTION_DAYS, 'name' => __('Days-based')],
            ['id' => Backup::RETENTION_GFS, 'name' => __('GFS (Grandfather-Father-Son)')],
            ['id' => Backup::RETENTION_FOREVER, 'name' => __('Forever (keep all snapshots)')],
        ];
    }

    /**
     * Get volume options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getVolumeOptions(): array
    {
        return \App\Models\Volume::orderBy('name')->get()->map(fn ($v) => [
            'id' => $v->id,
            'name' => "{$v->name} ({$v->type})",
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function formValidate(): array
    {
        $this->normalizeDatabaseNames();

        $rules = [
            'name' => 'required|string|max:255',
            'database_type' => 'required|string|in:mysql,postgres,sqlite',
            'description' => 'nullable|string|max:1000',
            'backups_enabled' => 'boolean',
        ];

        // Backup configuration rules (only required when backups are enabled)
        if ($this->backups_enabled) {
            $rules['volume_id'] = 'required|exists:volumes,id';
            $rules['path'] = ['nullable', 'string', 'max:255', new SafePath];
            $rules['recurrence'] = 'required|string|in:'.implode(',', Backup::RECURRENCE_TYPES);
            $rules['retention_policy'] = 'required|string|in:'.implode(',', Backup::RETENTION_POLICIES);

            // Conditional validation based on retention policy
            if ($this->retention_policy === Backup::RETENTION_DAYS) {
                $rules['retention_days'] = 'required|integer|min:1|max:365';
            } elseif ($this->retention_policy === Backup::RETENTION_GFS) {
                $rules['gfs_keep_daily'] = 'nullable|integer|min:0|max:90';
                $rules['gfs_keep_weekly'] = 'nullable|integer|min:0|max:52';
                $rules['gfs_keep_monthly'] = 'nullable|integer|min:0|max:24';
            }
            // RETENTION_FOREVER requires no additional fields
        }

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
            $rules['database_names'] = 'nullable|array';
            $rules['database_names.*'] = 'string|max:255';

            // database_names required when backups are enabled and not backing up all databases
            if ($this->backups_enabled && ! $this->backup_all_databases) {
                $rules['database_names'] = 'required|array|min:1';
            }
        }

        $validated = $this->validate($rules);

        // GFS policy requires at least one tier to be configured
        if ($this->backups_enabled
            && $this->retention_policy === Backup::RETENTION_GFS
            && empty($this->gfs_keep_daily)
            && empty($this->gfs_keep_weekly)
            && empty($this->gfs_keep_monthly)
        ) {
            throw ValidationException::withMessages([
                'form.gfs_keep_daily' => __('At least one retention tier must be configured.'),
            ]);
        }

        return $validated;
    }

    public function store(): bool
    {
        $validated = $this->formValidate();

        $this->testConnection();
        if (! $this->connectionTestSuccess) {
            return false;
        }

        [$serverData, $backupData] = $this->extractBackupData($validated);

        $server = DatabaseServer::create($serverData);
        $this->syncBackupConfiguration($server, $backupData);

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

        [$serverData, $backupData] = $this->extractBackupData($validated);

        // Only update password if a new one is provided
        if (isset($serverData['password']) && empty($serverData['password'])) {
            unset($serverData['password']);
        }
        if (! empty($serverData['backup_all_databases'])) {
            $serverData['database_names'] = null;
        }

        $this->server->update($serverData);
        $this->syncBackupConfiguration($this->server, $backupData);

        return true;
    }

    /**
     * Extract backup-related fields from validated data.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function extractBackupData(array $validated): array
    {
        $retentionPolicy = $validated['retention_policy'] ?? Backup::RETENTION_DAYS;

        $backupData = [
            'volume_id' => $validated['volume_id'] ?? '',
            'path' => ! empty($validated['path']) ? $validated['path'] : null,
            'recurrence' => $validated['recurrence'] ?? 'daily',
            'retention_policy' => $retentionPolicy,
        ];

        // Set retention fields based on policy
        if ($retentionPolicy === Backup::RETENTION_DAYS) {
            $backupData['retention_days'] = $validated['retention_days'] ?? null;
            $backupData['gfs_keep_daily'] = null;
            $backupData['gfs_keep_weekly'] = null;
            $backupData['gfs_keep_monthly'] = null;
        } elseif ($retentionPolicy === Backup::RETENTION_GFS) {
            $backupData['retention_days'] = null;
            // Normalize 0 to null for consistency (0 means "disabled" same as null)
            $backupData['gfs_keep_daily'] = ! empty($validated['gfs_keep_daily']) ? $validated['gfs_keep_daily'] : null;
            $backupData['gfs_keep_weekly'] = ! empty($validated['gfs_keep_weekly']) ? $validated['gfs_keep_weekly'] : null;
            $backupData['gfs_keep_monthly'] = ! empty($validated['gfs_keep_monthly']) ? $validated['gfs_keep_monthly'] : null;
        } else {
            // RETENTION_FOREVER - no retention fields needed
            $backupData['retention_days'] = null;
            $backupData['gfs_keep_daily'] = null;
            $backupData['gfs_keep_weekly'] = null;
            $backupData['gfs_keep_monthly'] = null;
        }

        unset(
            $validated['volume_id'],
            $validated['path'],
            $validated['recurrence'],
            $validated['retention_days'],
            $validated['retention_policy'],
            $validated['gfs_keep_daily'],
            $validated['gfs_keep_weekly'],
            $validated['gfs_keep_monthly']
        );

        return [$validated, $backupData];
    }

    /**
     * @param  array<string, mixed>  $backupData
     */
    private function syncBackupConfiguration(DatabaseServer $server, array $backupData): void
    {
        if ($server->backups_enabled) {
            $server->backup()->updateOrCreate(
                ['database_server_id' => $server->id],
                $backupData
            );
        }
    }

    public function testConnection(): void
    {
        $this->testingConnection = true;
        $this->connectionTestMessage = null;
        $this->connectionTestDetails = [];
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
        $this->connectionTestDetails = $result['details'] ?? [];
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
        } catch (\Exception $e) {
            // If we can't list databases (encryption error, connection error, etc.),
            // the user can still type manually
            // log the error but don't fail the form submission
            logger()->error('Failed to list databases for server', [
                'server_id' => $this->server->id ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->availableDatabases = [];
        }

        $this->loadingDatabases = false;
    }
}
