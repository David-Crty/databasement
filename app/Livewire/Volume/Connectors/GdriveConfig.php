<?php

namespace App\Livewire\Volume\Connectors;

class GdriveConfig extends BaseConfig
{
    /**
     * @return array{client_id: string, client_secret: string, refresh_token: string, folder_id: string}
     */
    public static function defaultConfig(): array
    {
        return [
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
            'folder_id' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.client_id" => ['required_if:type,gdrive', 'string', 'max:255'],
            "{$prefix}.client_secret" => ['required_if:type,gdrive', 'string', 'max:1000'],
            "{$prefix}.refresh_token" => ['required_if:type,gdrive', 'string', 'max:2000'],
            "{$prefix}.folder_id" => ['nullable', 'string', 'max:255'],
        ];
    }
}
