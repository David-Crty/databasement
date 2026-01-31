<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class S3Config extends Component
{
    /** @var array<string, mixed> */
    #[Modelable]
    public array $config = [];

    public bool $readonly = false;

    public function mount(): void
    {
        $this->config = array_merge(static::defaultConfig(), $this->config);
    }

    /**
     * @return array{bucket: string, prefix: string}
     */
    public static function defaultConfig(): array
    {
        return [
            'bucket' => '',
            'prefix' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.bucket" => ['required_if:type,s3', 'string', 'max:255'],
            "{$prefix}.prefix" => ['nullable', 'string', 'max:255', new SafePath],
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.volume.connectors.s3-config');
    }
}
