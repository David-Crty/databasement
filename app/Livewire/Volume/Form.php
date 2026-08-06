<?php

namespace App\Livewire\Volume;

use App\Enums\VolumeType;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Volume;
use App\Services\Agent\RemoteVolumeTester;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\CurrentOrganization;
use App\Services\VolumeConnectionTester;
use App\Support\Formatters;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Form extends \Livewire\Form
{
    public ?Volume $volume = null;

    public string $name = '';

    public string $type = 'local';

    // When true, the volume lives behind an agent: the app cannot reach it, so
    // its connection test runs on that agent instead.
    public bool $use_agent = false;

    public ?string $agent_id = null;

    // Optional storage quota for the volume, entered in GB. Empty = no limit.
    // Stored under the `max_storage_bytes` key of the volume's config JSON.
    public ?string $maxStorageGb = null;

    // When true, reaching the limit only sends a notification instead of failing
    // the backup. Stored under the `max_storage_notify_only` config key.
    public bool $storageLimitNotifyOnly = false;

    // Config arrays for each volume type (initialized from connector defaults in constructor)
    /** @var array<string, mixed> */
    public array $localConfig = [];

    /** @var array<string, mixed> */
    public array $s3Config = [];

    /** @var array<string, mixed> */
    public array $sftpConfig = [];

    /** @var array<string, mixed> */
    public array $ftpConfig = [];

    /** @var array<string, mixed> */
    public array $azureConfig = [];

    /** @var array<string, mixed> */
    public array $smbConfig = [];

    // Connection test state
    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    public bool $testingConnection = false;

    // Id of the agent job carrying a remote test, while it is in flight.
    public ?string $connectionTestJobId = null;

    public function __construct(
        Component $component,
        mixed $propertyName,
    ) {
        parent::__construct($component, $propertyName);

        // Initialize config arrays with defaults from each connector class
        foreach (VolumeType::cases() as $volumeType) {
            $configClass = $volumeType->configClass();
            $propertyName = $volumeType->configPropertyName();
            $this->{$propertyName} = $configClass::defaultConfig();
        }
    }

    public function setVolume(Volume $volume): void
    {
        $this->volume = $volume;
        $this->name = $volume->name;
        $this->type = $volume->type;
        $this->agent_id = $volume->agent_id;
        $this->use_agent = $volume->isRemote();

        // Load decrypted config, masking sensitive fields to prevent browser serialization
        $volumeType = VolumeType::from($volume->type);
        $decryptedConfig = $volumeType->maskSensitiveFields($volume->getDecryptedConfig());
        $propertyName = $volumeType->configPropertyName();
        $this->{$propertyName} = array_merge($this->{$propertyName}, $decryptedConfig);

        $this->maxStorageGb = Formatters::bytesToGb($volume->maxStorageBytes());
        $this->storageLimitNotifyOnly = $volume->storageLimitIsNotifyOnly();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', array_column(VolumeType::cases(), 'value'))],
            'maxStorageGb' => ['nullable', 'numeric', 'min:0.001'],
            'storageLimitNotifyOnly' => ['boolean'],
            'use_agent' => ['boolean'],
            'agent_id' => [
                'nullable',
                Rule::exists('agents', 'id')->where('organization_id', app(CurrentOrganization::class)->id()),
            ],
        ];

        if ($this->use_agent) {
            $rules['agent_id'][0] = 'required';
        }

        // Merge rules from all connector classes
        foreach (VolumeType::cases() as $volumeType) {
            $configClass = $volumeType->configClass();
            $rules = [...$rules, ...$configClass::rules($volumeType->configPropertyName())];
        }

        // When editing, make sensitive fields optional (blank to keep existing)
        if ($this->volume !== null) {
            $rules = VolumeType::from($this->type)->makeRulesOptionalForSensitiveFields($rules);
        }

        return $rules;
    }

    public function store(): void
    {
        $rules = $this->rules();
        $rules['name'][] = 'unique:volumes,name';

        $this->validate($rules);

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'agent_id' => $this->resolvedAgentId(),
            'config' => $this->buildConfig(),
        ];

        $data['organization_id'] = app(CurrentOrganization::class)->id();

        Volume::create($data);
    }

    public function update(): void
    {
        $rules = $this->rules();
        $rules['name'][] = 'unique:volumes,name,'.$this->volume->id;

        $this->validate($rules);

        $this->volume->update([
            'name' => $this->name,
            'agent_id' => $this->resolvedAgentId(),
            'config' => $this->buildConfig(),
        ]);
    }

    /**
     * The agent to bind the volume to, or null when the app reaches it directly.
     */
    private function resolvedAgentId(): ?string
    {
        return $this->use_agent ? $this->agent_id : null;
    }

    /**
     * Clear the selected agent when the toggle is switched off, so an unused
     * selection cannot leak into the saved volume.
     */
    public function updatedUseAgent(): void
    {
        if (! $this->use_agent) {
            $this->agent_id = null;
        }

        $this->resetConnectionTest();
    }

    public function updatedAgentId(): void
    {
        $this->resetConnectionTest();
    }

    private function resetConnectionTest(): void
    {
        $this->connectionTestJobId = null;
        $this->connectionTestMessage = null;
        $this->connectionTestSuccess = false;
        $this->testingConnection = false;
    }

    /**
     * Agents available to bind a volume to.
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
     * Update a volume that is locked by existing snapshots: only the name and
     * storage limit stay editable — the connector config is frozen because
     * stored snapshots already depend on it.
     */
    public function updateLockedVolume(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:volumes,name,'.$this->volume->id],
            'maxStorageGb' => ['nullable', 'numeric', 'min:0.001'],
            'storageLimitNotifyOnly' => ['boolean'],
        ]);

        $this->volume->update([
            'name' => $this->name,
            'config' => $this->applyMaxStorageToConfig($this->volume->config),
        ]);
    }

    /**
     * Get the active config array based on current type.
     *
     * @return array<string, mixed>
     */
    public function getActiveConfig(): array
    {
        return $this->{VolumeType::from($this->type)->configPropertyName()};
    }

    /**
     * Build the config array with sensitive fields encrypted.
     * Preserves existing encrypted values when the submitted field is empty.
     *
     * @return array<string, mixed>
     */
    protected function buildConfig(): array
    {
        $volumeType = VolumeType::from($this->type);
        $persistedConfig = $this->volume !== null ? $this->volume->config : [];

        $config = $volumeType->encryptSensitiveFields($this->getActiveConfig(), $persistedConfig);

        return $this->applyMaxStorageToConfig($config);
    }

    /**
     * Store the storage quota (GB from the form, in bytes) under the config's
     * `max_storage_bytes` key, along with the notify-only flag. Both keys are
     * removed when the limit field is left empty (the flag is meaningless
     * without a limit).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function applyMaxStorageToConfig(array $config): array
    {
        $bytes = Formatters::gbToBytes($this->maxStorageGb);

        if ($bytes === null) {
            unset($config['max_storage_bytes'], $config['max_storage_notify_only']);

            return $config;
        }

        $config['max_storage_bytes'] = $bytes;

        if ($this->storageLimitNotifyOnly) {
            $config['max_storage_notify_only'] = true;
        } else {
            unset($config['max_storage_notify_only']);
        }

        return $config;
    }

    public function testConnection(): void
    {
        $this->testingConnection = true;
        $this->connectionTestMessage = null;

        $volumeType = VolumeType::from($this->type);

        // Get validation rules for current type only
        $filteredRules = $volumeType->configRules();

        // When editing, make sensitive fields optional (blank to keep existing)
        if ($this->volume !== null) {
            $filteredRules = $volumeType->makeRulesOptionalForSensitiveFields($filteredRules);
        }

        try {
            $this->validate($filteredRules);
        } catch (ValidationException) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            $this->connectionTestMessage = 'Please fill in all required configuration fields.';

            return;
        }

        // Build config for testing, merging persisted sensitive values when form is empty
        $testConfig = $this->volume !== null
            ? $volumeType->mergeSensitiveFromPersisted($this->getActiveConfig(), $this->volume->getDecryptedConfig())
            : $this->getActiveConfig();

        // $testConfig already holds plaintext secrets, so it maps straight onto
        // the DTO both the local probe and the agent payload are built from.
        $volumeConfig = new VolumeConfig(
            type: $this->type,
            name: $this->name ?: 'test-volume',
            config: $testConfig,
            id: $this->volume?->id,
        );

        // A volume behind an agent is unreachable from here: hand the probe to
        // the agent and let the UI poll for its answer.
        if ($this->use_agent) {
            $this->dispatchRemoteConnectionTest($volumeConfig);

            return;
        }

        $result = app(VolumeConnectionTester::class)->testConfig($volumeConfig);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->testingConnection = false;
    }

    private function dispatchRemoteConnectionTest(VolumeConfig $volumeConfig): void
    {
        $agent = $this->getSelectedAgent();

        if ($agent === null) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            $this->connectionTestMessage = __('Select the agent that can reach this volume first.');

            return;
        }

        $job = app(RemoteVolumeTester::class)->dispatch($agent, $volumeConfig);

        $this->connectionTestJobId = $job->id;
        $this->connectionTestSuccess = false;
        $this->connectionTestMessage = __('Waiting for agent :name to test the volume...', ['name' => $agent->name]);
    }

    /**
     * Read back the result of a remote test. Polled by the form while a test is
     * in flight; a no-op otherwise.
     */
    public function pollConnectionTest(): void
    {
        if ($this->connectionTestJobId === null) {
            return;
        }

        $job = AgentJob::find($this->connectionTestJobId);
        $tester = app(RemoteVolumeTester::class);

        if ($job === null) {
            $this->finishRemoteConnectionTest(false, __('The volume test was cancelled.'));

            return;
        }

        $result = $tester->result($job);

        if ($result['state'] === 'success') {
            $this->finishRemoteConnectionTest(true, __('Connection successful!'));

            return;
        }

        if ($result['state'] === 'failed') {
            $this->finishRemoteConnectionTest(false, $result['message'] ?: __('The agent could not reach this volume.'));

            return;
        }

        if ($tester->hasTimedOut($job)) {
            $this->finishRemoteConnectionTest(false, __('The agent did not respond. Check that it is running and connected.'));
        }
    }

    private function finishRemoteConnectionTest(bool $success, string $message): void
    {
        $this->connectionTestJobId = null;
        $this->testingConnection = false;
        $this->connectionTestSuccess = $success;
        $this->connectionTestMessage = $message;
    }
}
