<?php

namespace App\Livewire\Forms;

use App\Enums\DatabaseType;
use App\Enums\NotificationChannelSelection;
use App\Enums\NotificationTrigger;
use App\Exceptions\Backup\EncryptionException;
use App\Models\Agent;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\NotificationChannel;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\SyncBackupConfigurationsAction;
use App\Services\SshTunnelService;
use Illuminate\Support\Facades\DB;
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

    public string $username = '';

    public string $password = '';

    public string $auth_source = '';

    public string $dump_flags = '';

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

    public ?string $description = null;

    public bool $use_agent = false;

    public ?string $agent_id = null;

    public bool $backups_enabled = true;

    // Notification preferences (server-level)
    public string $notification_trigger = 'failure';

    public string $notification_channel_selection = 'all';

    /** @var array<string> */
    public array $notification_channel_ids = [];

    /**
     * Collection of backup configurations attached to this server. Each
     * entry follows the shape returned by {@see BackupForm::defaults()}.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $backups = [];

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
     * Generic updated hook — used to reactively respond to changes in nested
     * array properties (e.g. `backups.0.database_selection_mode`) that magic
     * `updatedXxx` methods can't reach.
     */
    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^backups\.(\d+)\.retention_policy$/', $property, $matches)) {
            $this->onBackupRetentionPolicyChanged((int) $matches[1], (string) $value);

            return;
        }

        if (preg_match('/^backups\.(\d+)\.database_selection_mode$/', $property)) {
            $this->onBackupSelectionModeChanged();

            return;
        }

        // Clearing the comma-separated text input should clear the backing
        // array so stale values can't survive the save. Live-binding means
        // this fires while typing, so we only act on a full clear.
        if (preg_match('/^backups\.(\d+)\.database_names_input$/', $property, $matches)
            && (string) $value === ''
        ) {
            $index = (int) $matches[1];
            if (isset($this->backups[$index])) {
                $this->backups[$index]['database_names'] = [];
            }
        }
    }

    /**
     * When a backup's retention policy changes, restore sensible defaults for
     * the freshly-activated tier if the user previously zeroed them out.
     */
    private function onBackupRetentionPolicyChanged(int $index, string $value): void
    {
        if (! isset($this->backups[$index])) {
            return;
        }

        if ($value === Backup::RETENTION_DAYS && empty($this->backups[$index]['retention_days'])) {
            $this->backups[$index]['retention_days'] = 14;
        }

        if ($value === Backup::RETENTION_GFS
            && empty($this->backups[$index]['gfs_keep_daily'])
            && empty($this->backups[$index]['gfs_keep_weekly'])
            && empty($this->backups[$index]['gfs_keep_monthly'])
        ) {
            $this->backups[$index]['gfs_keep_daily'] = 7;
            $this->backups[$index]['gfs_keep_weekly'] = 4;
            $this->backups[$index]['gfs_keep_monthly'] = 12;
        }
    }

    /**
     * When any backup's selection mode changes, auto-load the server's
     * available database list (if we're editing a persisted server and the
     * list hasn't been loaded yet).
     */
    private function onBackupSelectionModeChanged(): void
    {
        if (! empty($this->availableDatabases)) {
            return;
        }

        if ($this->server === null || $this->isSqlite() || $this->isRedis()) {
            return;
        }

        $this->loadAvailableDatabases();
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

        // Ensure SQLite always has at least one path row on the first backup
        if ($value === 'sqlite') {
            foreach ($this->backups as $index => $backup) {
                if (empty($this->backups[$index]['database_names'])) {
                    $this->backups[$index]['database_names'] = [''];
                }
            }
        }

        // Pre-fill auth_source for MongoDB
        if ($value === 'mongodb' && $this->auth_source === '') {
            $this->auth_source = 'admin';
        }
    }

    /**
     * Filter `$availableDatabases` against a regex pattern for pattern-preview UI.
     *
     * @return array<string>
     */
    public function getFilteredDatabases(string $pattern): array
    {
        if ($pattern === '' || empty($this->availableDatabases)) {
            return [];
        }

        $databaseNames = array_column($this->availableDatabases, 'name');

        return DatabaseServer::filterDatabasesByPattern($databaseNames, $pattern);
    }

    /**
     * Called when use_agent changes - clear agent_id when toggled off.
     */
    public function updatedUseAgent(): void
    {
        if (! $this->use_agent) {
            $this->agent_id = null;
        }

        // Clear volume selection(s) if they were local (incompatible with agents)
        if ($this->use_agent) {
            foreach ($this->backups as $index => $backup) {
                $volumeId = $backup['volume_id'] ?? '';
                if ($volumeId !== ''
                    && \App\Models\Volume::whereKey($volumeId)->where('type', \App\Enums\VolumeType::LOCAL->value)->exists()
                ) {
                    $this->backups[$index]['volume_id'] = '';
                }
            }
        }

        $this->resetConnectionTestState();
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
        $this->auth_source = $server->getExtraConfig('auth_source', '');
        $this->dump_flags = $server->getExtraConfig('dump_flags', '');
        $this->username = $server->username ?? '';
        $this->description = $server->description;
        $this->agent_id = $server->agent_id;
        $this->use_agent = ! empty($server->agent_id);
        $this->backups_enabled = $server->backups_enabled ?? true;
        $this->notification_trigger = $server->notification_trigger?->value ?? 'failure'; // @phpstan-ignore nullCoalesce.expr
        $this->notification_channel_selection = $server->notification_channel_selection?->value ?? 'all'; // @phpstan-ignore nullCoalesce.expr
        $this->notification_channel_ids = $server->notificationChannels()->pluck('notification_channels.id')->toArray();
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

        // Load backup configurations
        $backups = $server->backups()->get();

        if ($backups->isEmpty()) {
            $this->backups = [BackupForm::defaults()];
            if ($server->database_type === DatabaseType::SQLITE) {
                $this->backups[0]['database_names'] = [''];
            }
        } else {
            $this->backups = $backups->map(fn (Backup $backup) => BackupForm::fromModel($backup))->all();
        }
    }

    /**
     * Add an empty backup config card to the collection.
     */
    public function addBackup(?string $defaultScheduleId = null): void
    {
        if ($defaultScheduleId === null) {
            $defaultScheduleId = BackupSchedule::where('name', 'Daily')->value('id');
        }

        $this->backups[] = BackupForm::defaults($defaultScheduleId);
    }

    /**
     * Remove the backup config card at the given index.
     *
     * Intentionally does NOT reindex the array: stable keys keep Livewire /
     * Alpine children (e.g. x-choices-offline) bound to their original
     * `form.backups.{index}.*` paths across re-renders.
     */
    public function removeBackup(int $index): void
    {
        if (count($this->backups) <= 1) {
            return;
        }

        unset($this->backups[$index]);
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
     * Normalize SQLite database_names across all backup cards (strip empty entries).
     */
    public function normalizeDatabaseNames(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        foreach ($this->backups as $index => $backup) {
            $names = $backup['database_names'] ?? [];
            $this->backups[$index]['database_names'] = array_values(array_filter(
                array_map('trim', $names),
            ));
        }
    }

    /**
     * Flatten, de-duplicate and return every SQLite file path across the
     * backup cards. Used for connection testing so any populated backup can
     * exercise the connection — not only the first one.
     *
     * @return array<int, string>
     */
    private function collectSqlitePaths(): array
    {
        $paths = [];

        foreach ($this->backups as $backup) {
            foreach ($backup['database_names'] ?? [] as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $paths[] = trim($path);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Add an empty SQLite file path row to the given backup card.
     */
    public function addDatabasePath(int $backupIndex): void
    {
        if (! isset($this->backups[$backupIndex])) {
            return;
        }

        $this->backups[$backupIndex]['database_names'][] = '';
    }

    /**
     * Remove one SQLite file path row from the given backup card.
     */
    public function removeDatabasePath(int $backupIndex, int $pathIndex): void
    {
        if (! isset($this->backups[$backupIndex]['database_names'])) {
            return;
        }

        $paths = $this->backups[$backupIndex]['database_names'];
        if (count($paths) <= 1) {
            return;
        }

        unset($paths[$pathIndex]);
        $this->backups[$backupIndex]['database_names'] = array_values($paths);
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
     * Check if current database type is MongoDB
     */
    public function isMongodb(): bool
    {
        return $this->database_type === 'mongodb';
    }

    /**
     * Check if current database type has optional credentials (username/password not required).
     */
    public function hasOptionalCredentials(): bool
    {
        return $this->isRedis() || $this->isMongodb();
    }

    /**
     * Check if current database type supports custom dump flags.
     */
    public function supportsDumpFlags(): bool
    {
        return ! $this->isSqlite() && $this->database_type !== '';
    }

    /**
     * Get a preview of the dump command for the current database type.
     */
    public function getDumpCommandPreview(): string
    {
        if (! $this->supportsDumpFlags()) {
            return '';
        }

        $type = DatabaseType::from($this->database_type);
        $config = [
            'host' => 'hostname',
            'port' => $type->defaultPort(),
            'database' => 'dbname',
            'dump_flags' => $this->dump_flags,
            'user' => 'user',
            'pass' => '********',
        ];

        if ($type === DatabaseType::MONGODB) {
            $config['auth_source'] = $this->auth_source ?: 'admin';
        }

        try {
            $provider = new DatabaseProvider;
            $database = $provider->makeConfigured($type, $config);

            return $database->dump('/path/to/output')->command;
        } catch (\Throwable) {
            return '';
        }
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
     * Load the full BackupSchedule collection (used by blade for display helpers).
     *
     * @return \Illuminate\Support\Collection<int, BackupSchedule>
     */
    public function getBackupSchedules(): \Illuminate\Support\Collection
    {
        return BackupSchedule::orderBy('name')->get();
    }

    /**
     * Get backup schedule options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getScheduleOptions(): array
    {
        return $this->getBackupSchedules()
            ->map(fn (BackupSchedule $schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name.' — '.$schedule->expression.' ('.\App\Support\Formatters::cronTranslation($schedule->expression).')',
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
     * Get agent options for select
     *
     * @return array<array{id: string, name: string}>
     */
    public function getAgentOptions(): array
    {
        return Agent::orderBy('name')->get()->map(fn (Agent $agent) => [
            'id' => $agent->id,
            'name' => $agent->name,
        ])->toArray();
    }

    public function hasAgent(): bool
    {
        return ! empty($this->agent_id);
    }

    public function getSelectedAgent(): ?Agent
    {
        if (! $this->hasAgent()) {
            return null;
        }

        return Agent::find($this->agent_id);
    }

    /**
     * Load the full Volume collection (used by blade for display helpers).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Volume>
     */
    public function getAllVolumes(): \Illuminate\Support\Collection
    {
        return \App\Models\Volume::orderBy('name')->get();
    }

    /**
     * Get volume options for select
     *
     * @return array<array{id: string, name: string, disabled: bool}>
     */
    public function getVolumeOptions(): array
    {
        return $this->getAllVolumes()->map(function ($v) {
            $isLocalWithAgent = $this->use_agent && $v->getVolumeType() === \App\Enums\VolumeType::LOCAL;

            return [
                'id' => $v->id,
                'name' => $isLocalWithAgent
                    ? "{$v->name} ({$v->type}) — ".__('not available for remote agents')
                    : "{$v->name} ({$v->type})",
                'disabled' => $isLocalWithAgent,
            ];
        })->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function formValidate(): array
    {
        $this->normalizeDatabaseNames();

        $serverType = DatabaseType::tryFrom($this->database_type);

        if ($this->backups_enabled && $serverType !== null) {
            foreach ($this->backups as $index => $entry) {
                BackupForm::normalizeDatabaseNames($this->backups[$index], $this->availableDatabases, $serverType);
                BackupForm::normalizeSelection($this->backups[$index], $serverType);
            }
        }

        $rules = $this->getBaseValidationRules();

        if ($this->backups_enabled && $serverType !== null) {
            $rules['backups'] = 'required|array|min:1';

            foreach ($this->backups as $index => $entry) {
                $rules = array_merge(
                    $rules,
                    BackupForm::rulesFor($index, $entry, $serverType, $this->hasAgent()),
                );
            }
        }

        if ($this->isSqlite()) {
            $rules = array_merge($rules, $this->getSqliteValidationRules());
        } elseif ($this->isRedis()) {
            $rules = array_merge($rules, $this->getRedisValidationRules());
        } elseif ($this->isMongodb()) {
            $rules = array_merge($rules, $this->getMongodbValidationRules());
        } else {
            $rules = array_merge($rules, $this->getClientServerValidationRules());
        }

        $validated = $this->validate($rules);

        if ($this->backups_enabled) {
            foreach ($this->backups as $index => $entry) {
                BackupForm::validatePatternMode($index, $entry);
                BackupForm::validateGfsPolicy($index, $entry);
            }
        }

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
            'agent_id' => 'nullable|exists:agents,id',
            'backups_enabled' => 'boolean',
            'dump_flags' => ['nullable', 'string', 'max:500', 'regex:/^[a-zA-Z0-9\s\-\_\=\.\/\,\:\*\?\%\+\@]+$/'],
            'notification_trigger' => ['required', 'string', Rule::in(array_column(NotificationTrigger::cases(), 'value'))],
            'notification_channel_selection' => ['required', 'string', Rule::in(array_column(NotificationChannelSelection::cases(), 'value'))],
            'notification_channel_ids' => ['array', Rule::requiredIf(
                $this->notification_trigger !== NotificationTrigger::None->value
                && $this->notification_channel_selection === NotificationChannelSelection::Selected->value
            )],
            'notification_channel_ids.*' => ['string', 'exists:notification_channels,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getSqliteValidationRules(): array
    {
        $rules = [
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
            'ssh_enabled' => 'boolean',
        ];

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
     * Get MongoDB-specific validation rules.
     *
     * @return array<string, mixed>
     */
    private function getMongodbValidationRules(): array
    {
        $rules = [
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable',
            'auth_source' => 'nullable|string|max:255',
            'ssh_enabled' => 'boolean',
        ];

        if ($this->ssh_enabled) {
            $rules['ssh_config_mode'] = 'required|string|in:existing,create';
            $rules = array_merge($rules, $this->getSshValidationRules());
        }

        return $rules;
    }

    public function store(): bool
    {
        $validated = $this->formValidate();

        $serverData = $this->extractServerData($validated);
        $serverData['ssh_config_id'] = $this->createOrUpdateSshConfig();
        DatabaseServer::buildExtraConfig($serverData);

        DB::transaction(function () use ($serverData): void {
            $server = DatabaseServer::create($serverData);
            $this->syncBackupConfigurations($server);
            $this->syncNotificationChannels($server);
        });

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

        $serverData = $this->extractServerData($validated);

        // Only update password if a new one is provided
        if (isset($serverData['password']) && $serverData['password'] === '') {
            unset($serverData['password']);
        }

        $serverData['ssh_config_id'] = $this->createOrUpdateSshConfig();
        DatabaseServer::buildExtraConfig($serverData, $this->server->extra_config, $this->server->database_type->value);

        DB::transaction(function () use ($serverData): void {
            $this->server->update($serverData);
            $this->syncBackupConfigurations($this->server);
            $this->syncNotificationChannels($this->server);
        });

        return true;
    }

    /**
     * Strip backup/notification-only fields from the validated payload so the
     * remaining array can be passed straight to Eloquent's mass assignment.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractServerData(array $validated): array
    {
        unset(
            $validated['backups'],
            $validated['notification_channel_ids'],
        );

        return $validated;
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

    private function syncBackupConfigurations(DatabaseServer $server): void
    {
        app(SyncBackupConfigurationsAction::class)->execute($server, $this->backups);
    }

    private function syncNotificationChannels(DatabaseServer $server): void
    {
        if ($this->notification_channel_selection === 'selected') {
            $server->notificationChannels()->sync($this->notification_channel_ids);
        } else {
            $server->notificationChannels()->detach();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, NotificationChannel>
     */
    public function getNotificationChannels(): \Illuminate\Database\Eloquent\Collection
    {
        return NotificationChannel::orderBy('name')->get();
    }

    public function toggleNotificationChannel(string $channelId): void
    {
        if (in_array($channelId, $this->notification_channel_ids, true)) {
            $this->notification_channel_ids = array_values(
                array_filter($this->notification_channel_ids, fn (string $id): bool => $id !== $channelId)
            );

            return;
        }

        $this->notification_channel_ids[] = $channelId;
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
                $this->normalizeDatabaseNames();
                if ($this->collectSqlitePaths() === []) {
                    throw ValidationException::withMessages([
                        'form.backups' => __('Add at least one SQLite database path before testing the connection.'),
                    ]);
                }
                if ($this->ssh_enabled) {
                    $this->validate($this->getSshValidationRules());
                }
            } elseif ($this->hasOptionalCredentials()) {
                $this->validate([
                    'host' => 'required|string|max:255',
                    'port' => 'required|integer|min:1|max:65535',
                ]);
            } else {
                $this->validate([
                    'host' => 'required|string|max:255',
                    'port' => 'required|integer|min:1|max:65535',
                    'username' => 'required|string|max:255',
                    'password' => ($this->server === null ? 'required|string|max:255' : 'nullable'),
                ]);
            }
        } catch (ValidationException $e) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            /** @var string $message */
            $message = collect($e->errors())->flatten()->first()
                ?? __('Please fill in all required connection fields.');
            $this->connectionTestMessage = $message;

            return;
        }

        // Test connection
        try {
            $password = $this->password ?: $this->server?->getDecryptedPassword();
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
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $password,
            'database_names' => $this->isSqlite() ? $this->collectSqlitePaths() : null,
            'extra_config' => $this->isMongodb() ? ['auth_source' => $this->auth_source] : null,
        ], $sshConfig);

        $result = app(DatabaseProvider::class)->testConnectionForServer($server);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->connectionTestDetails = $result['details'];
        $this->testingConnection = false;

        // If connection successful and supports per-database backups, load available databases
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
            $password = $this->password ?: $this->server?->getDecryptedPassword();

            // Build SSH config if enabled
            $sshConfig = $this->ssh_enabled ? $this->buildSshConfigForTest() : null;

            // Create a temporary DatabaseServer object for the service
            $tempServer = DatabaseServer::forConnectionTest([
                'host' => $this->host,
                'port' => $this->port,
                'database_type' => $this->database_type,
                'username' => $this->username,
                'password' => $password,
                'extra_config' => $this->isMongodb() ? ['auth_source' => $this->auth_source] : null,
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
