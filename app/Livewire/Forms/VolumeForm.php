<?php

namespace App\Livewire\Forms;

use App\Models\Volume;
use App\Rules\SafePath;
use App\Services\VolumeConnectionTester;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class VolumeForm extends Form
{
    public ?Volume $volume = null;

    public string $name = '';

    public string $type = 'local';

    // S3 Config
    public string $bucket = '';

    public string $prefix = '';

    // Local Config
    public string $path = '';

    // Connection test state
    public ?string $connectionTestMessage = null;

    public bool $connectionTestSuccess = false;

    public bool $testingConnection = false;

    public function setVolume(Volume $volume): void
    {
        $this->volume = $volume;
        $this->name = $volume->name;
        $this->type = $volume->type;

        /** @var array<string, mixed> $config */
        $config = $volume->config;

        // Load config based on type
        if ($volume->type === 's3') {
            $this->bucket = $config['bucket'] ?? '';
            $this->prefix = $config['prefix'] ?? '';
        } elseif ($volume->type === 'local') {
            $this->path = $config['path'] ?? '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:s3,local'],
            'bucket' => ['required_if:type,s3', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:255', new SafePath],
            'path' => ['required_if:type,local', 'string', 'max:500', new SafePath(allowAbsolute: true)],
        ];
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
     * @return array<string, string>
     */
    protected function buildConfig(): array
    {
        return match ($this->type) {
            's3' => [
                'bucket' => $this->bucket,
                'prefix' => $this->prefix ?? '',
            ],
            'local' => [
                'path' => $this->path,
            ],
            default => throw new \InvalidArgumentException("Invalid volume type: {$this->type}"),
        };
    }

    public function testConnection(): void
    {
        $this->testingConnection = true;
        $this->connectionTestMessage = null;

        // Validate type-specific fields only
        $rules = $this->rules();
        $fieldToValidate = $this->type === 'local' ? 'path' : 'bucket';

        try {
            $this->validate([$fieldToValidate => $rules[$fieldToValidate]]);
        } catch (ValidationException) {
            $this->testingConnection = false;
            $this->connectionTestSuccess = false;
            $this->connectionTestMessage = 'Please fill in all required configuration fields.';

            return;
        }

        /** @var VolumeConnectionTester $tester */
        $tester = app(VolumeConnectionTester::class);

        $testVolume = new Volume([
            'name' => $this->name ?: 'test-volume',
            'type' => $this->type,
            'config' => $this->buildConfig(),
        ]);

        $result = $tester->test($testVolume);

        $this->connectionTestSuccess = $result['success'];
        $this->connectionTestMessage = $result['message'];
        $this->testingConnection = false;
    }
}
