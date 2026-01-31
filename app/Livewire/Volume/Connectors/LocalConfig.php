<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class LocalConfig extends Component
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
     * @return array{path: string}
     */
    public static function defaultConfig(): array
    {
        return [
            'path' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.path" => ['required_if:type,local', 'string', 'max:500', new SafePath(allowAbsolute: true)],
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.volume.connectors.local-config');
    }
}
