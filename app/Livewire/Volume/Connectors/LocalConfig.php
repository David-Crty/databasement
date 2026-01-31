<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;

class LocalConfig extends BaseConfig
{
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

    protected function viewName(): string
    {
        return 'livewire.volume.connectors.local-config';
    }
}
