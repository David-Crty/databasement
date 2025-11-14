<?php

namespace App\Livewire\Forms;

use App\Models\Volume;
use Livewire\Attributes\Validate;
use Livewire\Form;

class VolumeForm extends Form
{
    public ?Volume $volume = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|in:s3,local')]
    public string $type = 'local';

    // S3 Config
    #[Validate('required_if:type,s3|string|max:255')]
    public string $bucket = '';

    #[Validate('nullable|string|max:255')]
    public string $prefix = '';

    // Local Config
    #[Validate('required_if:type,local|string|max:500')]
    public string $path = '';

    public function setVolume(Volume $volume)
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

    public function store()
    {
        // Validate with unique rule for new volumes
        $this->validate([
            'name' => 'required|string|max:255|unique:volumes,name',
            'type' => 'required|string|in:s3,local',
            'bucket' => 'required_if:type,s3|string|max:255',
            'prefix' => 'nullable|string|max:255',
            'path' => 'required_if:type,local|string|max:500',
        ]);

        $config = $this->buildConfig();

        Volume::create([
            'name' => $this->name,
            'type' => $this->type,
            'config' => $config,
        ]);
    }

    public function update()
    {
        // Add unique validation for name, ignoring current volume
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:volumes,name,'.$this->volume->id],
            'type' => 'required|string|in:s3,local',
            'bucket' => 'required_if:type,s3|string|max:255',
            'prefix' => 'nullable|string|max:255',
            'path' => 'required_if:type,local|string|max:500',
        ]);

        $config = $this->buildConfig();

        $this->volume->update([
            'name' => $this->name,
            'type' => $this->type,
            'config' => $config,
        ]);
    }

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
}
