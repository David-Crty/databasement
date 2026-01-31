<?php

namespace App\Livewire\Volume\Connectors;

use Livewire\Attributes\Modelable;
use Livewire\Component;

class FtpConfig extends Component
{
    /** @var array<string, mixed> */
    #[Modelable]
    public array $config = [];

    public bool $readonly = false;

    public bool $isEditing = false;

    public function mount(): void
    {
        $this->config = array_merge(static::defaultConfig(), $this->config);
    }

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

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.volume.connectors.ftp-config');
    }
}
