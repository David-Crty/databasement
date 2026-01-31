<?php

namespace App\Livewire\Forms;

use App\Enums\VolumeType;
use App\Livewire\Volume\Connectors\FtpConfig;
use App\Livewire\Volume\Connectors\LocalConfig;
use App\Livewire\Volume\Connectors\S3Config;
use App\Livewire\Volume\Connectors\SftpConfig;
use App\Models\Volume;
use App\Services\VolumeConnectionTester;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Form;

class VolumeForm extends Form
{
    public ?Volume $volume = null;

    public string $name = '';

    public string $type = 'local';

    // Config arrays for each volume type (initialized from connector defaults in constructor)
    /** @var array<string, mixed> */
    public array $localConfig = [];

    /** @var array<string, mixed> */
    public array $s3Config = [];

    /** @var array<string, mixed> */
    public array $sftpConfig = [];

    /** @var array<string, mixed> */
    public array $ftpConfig = [];

    // Connection test state
    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    public bool $testingConnection = false;

    public function __construct(
        Component $component,
        mixed $propertyName,
    ) {
        parent::__construct($component, $propertyName);

        $this->localConfig = LocalConfig::defaultConfig();
        $this->s3Config = S3Config::defaultConfig();
        $this->sftpConfig = SftpConfig::defaultConfig();
        $this->ftpConfig = FtpConfig::defaultConfig();
    }

    public function setVolume(Volume $volume): void
    {
        $this->volume = $volume;
        $this->name = $volume->name;
        $this->type = $volume->type;

        // Load decrypted config, masking sensitive fields to prevent browser serialization
        $volumeType = VolumeType::from($volume->type);
        $decryptedConfig = $volumeType->maskSensitiveFields($volume->getDecryptedConfig());
        $propertyName = $volumeType->configPropertyName();
        $this->{$propertyName} = array_merge($this->{$propertyName}, $decryptedConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:local,s3,sftp,ftp'],
            ...LocalConfig::rules('localConfig'),
            ...S3Config::rules('s3Config'),
            ...SftpConfig::rules('sftpConfig'),
            ...FtpConfig::rules('ftpConfig'),
        ];

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

        Volume::create([
            'name' => $this->name,
            'type' => $this->type,
            'config' => $this->buildConfig(),
        ]);
    }

    public function update(): void
    {
        $rules = $this->rules();
        $rules['name'][] = 'unique:volumes,name,'.$this->volume->id;

        $this->validate($rules);

        $this->volume->update([
            'name' => $this->name,
            'type' => $this->type,
            'config' => $this->buildConfig(),
        ]);
    }

    public function updateNameOnly(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:volumes,name,'.$this->volume->id],
        ]);

        $this->volume->update([
            'name' => $this->name,
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

        return $volumeType->encryptSensitiveFields($this->getActiveConfig(), $persistedConfig);
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

        /** @var VolumeConnectionTester $tester */
        $tester = app(VolumeConnectionTester::class);

        $testVolume = new Volume([
            'name' => $this->name ?: 'test-volume',
            'type' => $this->type,
            'config' => $testConfig,
        ]);

        $result = $tester->test($testVolume);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->testingConnection = false;
    }
}
