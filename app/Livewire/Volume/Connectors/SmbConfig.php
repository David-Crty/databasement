<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;

class SmbConfig extends BaseConfig
{
    /**
     * @return array{host: string, share: string, username: string, password: string, domain: string, root: string}
     */
    public static function defaultConfig(): array
    {
        return [
            'host' => '',
            'share' => '',
            'username' => '',
            'password' => '',
            'domain' => '',
            'root' => '/',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.host" => ['required_if:type,smb', 'string', 'max:255'],
            "{$prefix}.share" => ['required_if:type,smb', 'string', 'max:255'],
            "{$prefix}.username" => ['required_if:type,smb', 'string', 'max:255'],
            "{$prefix}.password" => ['required_if:type,smb', 'string', 'max:1000'],
            "{$prefix}.domain" => ['nullable', 'string', 'max:255'],
            "{$prefix}.root" => ['nullable', 'string', 'max:500', new SafePath(allowAbsolute: true)],
        ];
    }
}
