<?php

namespace App\Livewire\Volume\Connectors;

class SftpConfig extends BaseConfig
{
    /**
     * @return array{host: string, port: int, username: string, password: string, root: string, timeout: int}
     */
    public static function defaultConfig(): array
    {
        return [
            'host' => '',
            'port' => 22,
            'username' => '',
            'password' => '',
            'root' => '/',
            'timeout' => 10,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.host" => ['required_if:type,sftp', 'string', 'max:255'],
            "{$prefix}.port" => ['nullable', 'integer', 'min:1', 'max:65535'],
            "{$prefix}.username" => ['required_if:type,sftp', 'string', 'max:255'],
            "{$prefix}.password" => ['required_if:type,sftp', 'string', 'max:1000'],
            "{$prefix}.root" => ['nullable', 'string', 'max:500'],
            "{$prefix}.timeout" => ['nullable', 'integer', 'min:1', 'max:300'],
        ];
    }

    protected function viewName(): string
    {
        return 'livewire.volume.connectors.sftp-config';
    }
}
