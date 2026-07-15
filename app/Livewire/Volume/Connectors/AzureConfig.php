<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;

class AzureConfig extends BaseConfig
{
    /**
     * @return array{account_name: string, account_key: string, container: string, prefix: string, endpoint_suffix: string, endpoint: string}
     */
    public static function defaultConfig(): array
    {
        return [
            'account_name' => '',
            'account_key' => '',
            'container' => '',
            'prefix' => '',
            'endpoint_suffix' => 'core.windows.net',
            'endpoint' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.account_name" => ['required_if:type,azure', 'string', 'max:255'],
            "{$prefix}.account_key" => ['required_if:type,azure', 'string', 'max:1000'],
            "{$prefix}.container" => ['required_if:type,azure', 'string', 'max:255'],
            "{$prefix}.prefix" => ['nullable', 'string', 'max:255', new SafePath],
            "{$prefix}.endpoint_suffix" => ['nullable', 'string', 'max:255'],
            "{$prefix}.endpoint" => ['nullable', 'string', 'max:255'],
        ];
    }
}
