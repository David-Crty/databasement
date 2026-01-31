<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;

class S3Config extends BaseConfig
{
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

    protected function viewName(): string
    {
        return 'livewire.volume.connectors.s3-config';
    }
}
