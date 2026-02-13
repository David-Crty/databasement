<?php

namespace App\Livewire\Forms;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\EncryptionException;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Rules\SafePath;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\SshTunnelService;
use App\Support\Formatters;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class DatabaseServerForm extends Form
{
    public ?DatabaseServer $server = null;

    public string $name = '';

    public string $host = '';

    public int $port = 3306;

    public string $database_type = '';

    public string $sqlite_path = '';

    public string $username = '';

    public string $password = '';

    // SSH Tunnel Configuration
    public bool $ssh_enabled = false;

    /** @var string 'existing' or 'create' - only relevant when ssh_enabled is true */
    public string $ssh_config_mode = 'create';

    /** @var string|null ID of existing SSH config to use */
    public ?string $ssh_config_id = null;

    public string $ssh_host = '';

    public int $ssh_port = 22;

    public string $ssh_username = '';

    public string $ssh_auth_type = 'password';

    public string $ssh_password = '';

    public string $ssh_private_key = '';

    public string $ssh_key_passphrase = '';

    public ?string $sshTestMessage = null;

    public bool $sshTestSuccess = false;

    public bool $testingSshConnection = false;

    /** @var array<string> */
    public array $database_names = [];

    /** @var string Input field for manual database entry (comma-separated) */
    public string $database_names_input = '';

    public bool $backup_all_databases = false;

    public ?string $description = null;

    public bool $backups_enabled = true;

    public string $volume_id = '';

    public string $path = '';

    public string $backup_schedule_id = '';

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
        $this->resetConnectionTestState();
        $this->availableDatabases = [];
    }

    /**
     * Called when ssh_enabled changes - reset SSH test state.
     */
    public function updatedSshEnabled(): void
    {
        $this->resetSshTestState();
        $this->resetConnectionTestState();
    }

    /**
     * Called when ssh_config_mode changes - load existing config data if selecting existing.
     */
    public function updatedSshConfigMode(): void
    {
        $this->resetSshTestState();
        $this->resetConnectionTestState();

        if ($this->ssh_config_mode === 'existing') {
            // Auto-select first config if none selected
            if (! $this->ssh_config_id) {
                $firstConfig = DatabaseServerSshConfig::first();
                if ($firstConfig) {
                    $this->ssh_config_id = $firstConfig->id;
                }
            }
            if ($this->ssh_config_id) {
                $this->loadSshConfigFromId($this->ssh_config_id);
            }
        } elseif ($this->ssh_config_mode === 'create') {
            $this->resetSshFormFields();
        }
    }

    /**
     * Called when ssh_config_id changes - load the selected config.
     */
    public function updatedSshConfigId(): void
    {
        $this->resetSshTestState();
        $this->resetConnectionTestState();

        if ($this->ssh_config_id) {
            $this->loadSshConfigFromId($this->ssh_config_id);
        }
    }

    /**
     * Called when ssh_auth_type changes - reset SSH test state.
     */
    public function updatedSshAuthType(): void
    {
        $this->resetSshTestState();
    }

    /**
     * Load SSH config form fields from an existing config ID.
     */
    private function loadSshConfigFromId(string $id): void
    {
        $config = DatabaseServerSshConfig::find($id);
        if ($config === null) {
            return;
        }

        $this->ssh_host = $config->host;
        $this->ssh_port = $config->port;
        $this->ssh_username = $config->username;
        $this->ssh_auth_type = $config->auth_type;
        // Don't populate sensitive fields for security
        $this->ssh_password = '';
        $this->ssh_private_key = '';
        $this->ssh_key_passphrase = '';
    }

    /**
     * Reset SSH form fields to defaults.
     */
    private function resetSshFormFields(): void
    {
        $this->ssh_config_id = null;
        $this->ssh_host = '';
        $this->ssh_port = 22;
        $this->ssh_username = '';
        $this->ssh_auth_type = 'password';
        $this->ssh_password = '';
        $this->ssh_private_key = '';
        $this->ssh_key_passphrase = '';
    }

    /**
     * Reset SSH connection test state.
     */
    private function resetSshTestState(): void
    {
        $this->sshTestSuccess = false;
        $this->sshTestMessage = null;
    }

    /**
     * Reset database connection test state.
     */
    private function resetConnectionTestState(): void
    {
        $this->connectionTestSuccess = false;
        $this->connectionTestMessage = null;
        $this->connectionTestDetails = [];
        $this->showConnectionDetails = false;
    }

    public function setServer(DatabaseServer $server): void
    {
        $this->server = $server;
        $this->name = $server->name;
        $this->host = $server->host ?? '';
        $this->port = $server->port ?? 3306;
        $this->database_type = $server->database_type->value;
        $this->sqlite_path = $server->sqlite_path ?? '';
        $this->username = $server->username ?? '';
        $this->database_names = $server->database_names ?? [];
        $this->database_names_input = implode(', ', $this->database_names);
        $this->backup_all_databases = $server->backup_all_databases ?? false;
        $this->description = $server->description;
        $this->backups_enabled = $server->backups_enabled ?? true;
        // Don't populate password for security
        $this->password = '';

        // Load SSH config if exists
        if ($server->sshConfig !== null) {
            $this->ssh_enabled = true;
            $this->ssh_config_mode = 'existing';
            $this->ssh_config_id = $server->ssh_config_id;
            $this->loadSshConfigFromId($server->ssh_config_id);
        } else {
            $this->ssh_enabled = false;
            $this->ssh_config_mode = 'create';
            $this->ssh_config_id = null;
        }

        // Load backup data if exists
        if ($server->backup) {
            /** @var Backup $backup */
            $backup = $server->backup;
            $this->volume_id = $backup->volume_id;
            $this->path = $backup->path ?? '';
            $this->backup_schedule_id = $backup->backup_schedule_id ?? '';
            $this->retention_days = $backup->retention_days;
            $this->retention_policy = $backup->retention_policy ?? Backup::RETENTION_DAYS;
            $this->gfs_keep_daily = $backup->gfs_keep_daily;
            $this->gfs_keep_weekly = $backup->gfs_keep_weekly;
            $this->gfs_keep_monthly = $backup->gfs_keep_monthly;
        }
    }

    /**
     * Get existing SSH configurations for dropdown.
     *
     * @return array<array{id: string, name: string}>
     */
    public function getSshConfigOptions(): array
    {
        return DatabaseServerSshConfig::orderBy('host')
            ->get()
            ->map(fn (DatabaseServerSshConfig $config) => [
                'id' => $config->id,
                'name' => $config->getDisplayName(),
            ])
            ->toArray();
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
     * Check if current database type is Redis
     */
    public function isRedis(): bool
    {
        return $this->database_type === 'redis';
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
     * Get backup schedule options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getScheduleOptions(): array
    {
        return BackupSchedule::orderBy('name')
            ->get()
            ->map(fn (BackupSchedule $schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name.' — '.$schedule->expression.' ('.Formatters::cronTranslation($schedule->expression).')',
            ])
            ->toArray();
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

        $rules = $this->getBaseValidationRules();

        if ($this->backups_enabled) {
            $rules = array_merge($rules, $this->getBackupValidationRules());
        }

        if ($this->isSqlite()) {
            $rules = array_merge($rules, $this->getSqliteValidationRules());
        } elseif ($this->isRedis()) {
            $rules = array_merge($rules, $this->getRedisValidationRules());
        } else {
            $rules = array_merge($rules, $this->getClientServerValidationRules());
        }

        $validated = $this->validate($rules);

        $this->validateGfsPolicy();

        return $validated;
    }

    /**
     * Get base validation rules for all database servers.
     *
     * @return array<string, mixed>
     */
    private function getBaseValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'database_type' => ['required', 'string', Rule::in(array_map(
                fn (DatabaseType $type) => $type->value,
                DatabaseType::cases()
            ))],
            'description' => 'nullable|string|max:1000',
            'backups_enabled' => 'boolean',
        ];
    }

    /**
     * Get backup configuration validation rules.
     *
     * @return array<string, mixed>
     */
    private function getBackupValidationRules(): array
    {
        $rules = [
            'volume_id' => 'required|exists:volumes,id',
            'path' => ['nullable', 'string', 'max:255', new SafePath],
            'backup_schedule_id' => 'required|exists:backup_schedules,id',
            'retention_policy' => 'required|string|in:'.implode(',', Backup::RETENTION_POLICIES),
        ];

        if ($this->retention_policy === Backup::RETENTION_DAYS) {
            $rules['retention_days'] = 'required|integer|min:1|max:365';
        } elseif ($this->retention_policy === Backup::RETENTION_GFS) {
            $rules['gfs_keep_daily'] = 'nullable|integer|min:0|max:90';
            $rules['gfs_keep_weekly'] = 'nullable|integer|min:0|max:52';
            $rules['gfs_keep_monthly'] = 'nullable|integer|min:0|max:24';
        }

        return $rules;
    }

    /**
     * Get SQLite-specific validation rules.
     *
     * @return array<string, mixed>
     */
    private function getSqliteValidationRules(): array
    {
        $rules = [
            'sqlite_path' => 'required|string|max:1000',
            'ssh_enabled' => 'boolean',
        ];

        if ($this->ssh_enabled) {
            $rules['ssh_config_mode'] = 'required|string|in:existing,create';
            $rules = array_merge($rules, $this->getSshValidationRules());
        }

        return $rules;
    }

    /**
     * Get client-server database validation rules.
     *
     * @return array<string, mixed>
     */
    private function getClientServerValidationRules(): array
    {
        $rules = [
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'nullable',
            'backup_all_databases' => 'boolean',
            'database_names' => 'nullable|array',
            'database_names.*' => 'string|max:255',
            'ssh_enabled' => 'boolean',
        ];

        if ($this->backups_enabled && ! $this->backup_all_databases) {
            $rules['database_names'] = 'required|array|min:1';
        }

        if ($this->ssh_enabled) {
            $rules['ssh_config_mode'] = 'required|string|in:existing,create';
            $rules = array_merge($rules, $this->getSshValidationRules());
        }

        return $rules;
    }

    /**
     * Get Redis-specific validation rules.
     *
     * @return array<string, mixed>
     */
    private function getRedisValidationRules(): array
    {
        $rules = [
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable',
            'ssh_enabled' => 'boolean',
        ];

        if ($this->ssh_enabled) {
            $rules['ssh_config_mode'] = 'required|string|in:existing,create';
            $rules = array_merge($rules, $this->getSshValidationRules());
        }

        return $rules;
    }

    /**
     * Validate GFS retention policy has at least one tier configured.
     *
     * @throws ValidationException
     */
    private function validateGfsPolicy(): void
    {
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
    }

    public function store(): bool
    {
        $validated = $this->formValidate();

        [$serverData, $backupData] = $this->extractBackupData($validated);

        // Handle SSH config
        $serverData['ssh_config_id'] = $this->createOrUpdateSshConfig();

        // Redis always backs up the entire instance
        if ($this->isRedis()) {
            $serverData['backup_all_databases'] = true;
            $serverData['database_names'] = null;
        }

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

        // Handle SSH config
        $serverData['ssh_config_id'] = $this->createOrUpdateSshConfig();

        // Redis always backs up the entire instance
        if ($this->isRedis()) {
            $serverData['backup_all_databases'] = true;
            $serverData['database_names'] = null;
        }

        $this->server->update($serverData);
        $this->syncBackupConfiguration($this->server, $backupData);

        return true;
    }

    /**
     * Create or update SSH config based on form state.
     * Returns the SSH config ID to link to the server.
     */
    private function createOrUpdateSshConfig(): ?string
    {
        if (! $this->ssh_enabled) {
            return null;
        }

        $sshData = [
            'host' => $this->ssh_host,
            'port' => $this->ssh_port,
            'username' => $this->ssh_username,
            'auth_type' => $this->ssh_auth_type,
        ];

        // Add sensitive fields if provided
        if ($this->ssh_auth_type === 'password') {
            if (! empty($this->ssh_password)) {
                $sshData['password'] = $this->ssh_password;
            }
            $sshData['private_key'] = null;
            $sshData['key_passphrase'] = null;
        } else {
            if (! empty($this->ssh_private_key)) {
                $sshData['private_key'] = $this->ssh_private_key;
            }
            if (! empty($this->ssh_key_passphrase)) {
                $sshData['key_passphrase'] = $this->ssh_key_passphrase;
            }
            $sshData['password'] = null;
        }

        // Determine which config to update (if any)
        $existingConfigId = $this->ssh_config_mode === 'existing'
            ? $this->ssh_config_id
            : $this->server?->ssh_config_id;

        if ($existingConfigId !== null) {
            $config = DatabaseServerSshConfig::find($existingConfigId);
            if ($config !== null) {
                // Non-sensitive fields are always updated; sensitive fields only when provided
                $nonSensitiveFields = ['host', 'port', 'username', 'auth_type'];
                $updateData = array_intersect_key($sshData, array_flip($nonSensitiveFields));

                foreach (DatabaseServerSshConfig::SENSITIVE_FIELDS as $field) {
                    if (! empty($sshData[$field])) {
                        $updateData[$field] = $sshData[$field];
                    }
                }

                $config->update($updateData);

                return $config->id;
            }
        }

        // Create new config
        return DatabaseServerSshConfig::create($sshData)->id;
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
            'backup_schedule_id' => $validated['backup_schedule_id'] ?? '',
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
            $validated['backup_schedule_id'],
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
                $rules = ['sqlite_path' => 'required|string|max:1000'];
                if ($this->ssh_enabled) {
                    $rules = array_merge($rules, $this->getSshValidationRules());
                }
                $this->validate($rules);
            } elseif ($this->isRedis()) {
                $this->validate([
                    'host' => 'required|string|max:255',
                    'port' => 'required|integer|min:1|max:65535',
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

        // Build SSH config for connection test
        $sshConfig = $this->ssh_enabled
            ? $this->buildSshConfigForTest()
            : null;

        $server = DatabaseServer::forConnectionTest([
            'database_type' => $this->database_type,
            'host' => $this->isSqlite() ? $this->sqlite_path : $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $password,
            'sqlite_path' => $this->isSqlite() ? $this->sqlite_path : null,
        ], $sshConfig);

        $result = app(DatabaseProvider::class)->testConnectionForServer($server);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->connectionTestDetails = $result['details'];
        $this->testingConnection = false;

        // If connection successful and not SQLite/Redis, load available databases
        if ($this->connectionTestSuccess && ! $this->isSqlite() && ! $this->isRedis()) {
            $this->loadAvailableDatabases();
        }
    }

    /**
     * Test SSH connection independently.
     */
    public function testSshConnection(): void
    {
        $this->testingSshConnection = true;
        $this->sshTestMessage = null;
        $this->sshTestSuccess = false;

        // Validate SSH fields
        try {
            $this->validate($this->getSshValidationRules());
        } catch (ValidationException $e) {
            $this->testingSshConnection = false;
            $this->sshTestMessage = 'Please fill in all required SSH connection fields.';

            return;
        }

        $sshConfig = $this->buildSshConfigForTest();
        $result = app(SshTunnelService::class)->testConnection($sshConfig);

        $this->sshTestSuccess = $result['success'];
        $this->sshTestMessage = $result['message'];
        $this->testingSshConnection = false;
    }

    /**
     * Get a decrypted SSH config field from the existing config (either linked or selected).
     */
    private function getSshFieldFromConfig(string $field): ?string
    {
        $configId = $this->ssh_config_id;

        // If editing server with linked config, use server's config
        if ($this->server !== null && $this->server->sshConfig !== null) {
            $configId = $this->server->ssh_config_id;
        }

        if ($configId === null) {
            return null;
        }

        $config = DatabaseServerSshConfig::find($configId);
        if ($config === null) {
            return null;
        }

        $decrypted = $config->getDecrypted();

        return $decrypted[$field] ?? null;
    }

    /**
     * Build SSH config model for connection testing.
     * Creates an unsaved model instance with form values.
     */
    private function buildSshConfigForTest(): DatabaseServerSshConfig
    {
        $config = new DatabaseServerSshConfig;
        $config->host = $this->ssh_host;
        $config->port = $this->ssh_port;
        $config->username = $this->ssh_username;
        $config->auth_type = $this->ssh_auth_type;

        // Use form values or fall back to existing config values
        $config->password = $this->ssh_password ?: $this->getSshFieldFromConfig('password');
        $config->private_key = $this->ssh_private_key ?: $this->getSshFieldFromConfig('private_key');
        $config->key_passphrase = $this->ssh_key_passphrase ?: $this->getSshFieldFromConfig('key_passphrase');

        return $config;
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

            // Build SSH config if enabled
            $sshConfig = $this->ssh_enabled ? $this->buildSshConfigForTest() : null;

            // Create a temporary DatabaseServer object for the service
            $tempServer = DatabaseServer::forConnectionTest([
                'host' => $this->host,
                'port' => $this->port,
                'database_type' => $this->database_type,
                'username' => $this->username,
                'password' => $password,
            ], $sshConfig);

            $databases = app(DatabaseProvider::class)->listDatabasesForServer($tempServer);

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

    /**
     * Get SSH auth type options for select.
     *
     * @return array<array{id: string, name: string}>
     */
    public function getSshAuthTypeOptions(): array
    {
        return [
            ['id' => 'password', 'name' => __('Password')],
            ['id' => 'key', 'name' => __('Private Key')],
        ];
    }

    /**
     * Get SSH config mode options for select.
     *
     * @return array<array{id: string, name: string}>
     */
    public function getSshConfigModeOptions(): array
    {
        return [
            ['id' => 'existing', 'name' => __('Use existing')],
            ['id' => 'create', 'name' => __('Create new')],
        ];
    }

    /**
     * Get SSH validation rules.
     *
     * @return array<string, string>
     */
    private function getSshValidationRules(): array
    {
        $rules = [
            'ssh_host' => 'required|string|max:255',
            'ssh_port' => 'required|integer|min:1|max:65535',
            'ssh_username' => 'required|string|max:255',
            'ssh_auth_type' => 'required|string|in:password,key',
        ];

        // Sensitive fields are optional when editing existing server or using existing SSH config
        $credentialsOptional = ($this->ssh_config_mode === 'existing' && $this->ssh_config_id) || $this->server !== null;
        $credentialRule = $credentialsOptional ? 'nullable|string' : 'required|string';

        if ($this->ssh_auth_type === 'password') {
            $rules['ssh_password'] = $credentialRule;
        } else {
            $rules['ssh_private_key'] = $credentialRule;
            $rules['ssh_key_passphrase'] = 'nullable|string';
        }

        return $rules;
    }
}
