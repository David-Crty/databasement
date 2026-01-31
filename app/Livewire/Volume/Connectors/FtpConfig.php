<?php

namespace App\Livewire\Volume\Connectors;

class FtpConfig extends BaseConfig
{
    /**
     * @return array{host: string, port: int, username: string, password: string, root: string, ssl: bool, passive: bool, timeout: int}
     */
    public static function defaultConfig(): array
    {
        return [
            'host' => '',
            'port' => 21,
            'username' => '',
            'password' => '',
            'root' => '/',
            'ssl' => false,
            'passive' => true,
            'timeout' => 90,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.host" => ['required_if:type,ftp', 'string', 'max:255'],
            "{$prefix}.port" => ['nullable', 'integer', 'min:1', 'max:65535'],
            "{$prefix}.username" => ['required_if:type,ftp', 'string', 'max:255'],
            "{$prefix}.password" => ['required_if:type,ftp', 'string', 'max:1000'],
            "{$prefix}.root" => ['nullable', 'string', 'max:500'],
            "{$prefix}.ssl" => ['nullable', 'boolean'],
            "{$prefix}.passive" => ['nullable', 'boolean'],
            "{$prefix}.timeout" => ['nullable', 'integer', 'min:1', 'max:300'],
        ];
    }

    protected function viewName(): string
    {
        return 'livewire.volume.connectors.ftp-config';
    }
}
