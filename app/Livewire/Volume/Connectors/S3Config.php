<?php

namespace App\Livewire\Volume\Connectors;

use App\Rules\SafePath;

class S3Config extends BaseConfig
{
    /**
     * @return array{bucket: string, prefix: string, region: string, access_key_id: string, secret_access_key: string, custom_endpoint: string, public_endpoint: string, use_path_style_endpoint: bool, custom_role_arn: string, role_session_name: string, sts_endpoint: string}
     */
    public static function defaultConfig(): array
    {
        return [
            'bucket' => '',
            'prefix' => '',
            'region' => 'us-east-1',
            'access_key_id' => '',
            'secret_access_key' => '',
            'custom_endpoint' => '',
            'public_endpoint' => '',
            'use_path_style_endpoint' => false,
            'custom_role_arn' => '',
            'role_session_name' => '',
            'sts_endpoint' => '',
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
            "{$prefix}.region" => ['required_if:type,s3', 'string', 'max:255'],
            "{$prefix}.access_key_id" => ["required_with:{$prefix}.secret_access_key", 'nullable', 'string', 'max:255'],
            "{$prefix}.secret_access_key" => ["required_with:{$prefix}.access_key_id", 'nullable', 'string', 'max:1000'],
            "{$prefix}.custom_endpoint" => ['nullable', 'string', 'max:255'],
            "{$prefix}.public_endpoint" => ['nullable', 'string', 'max:255'],
            "{$prefix}.use_path_style_endpoint" => ['nullable', 'boolean'],
            "{$prefix}.custom_role_arn" => ['nullable', 'string', 'max:255'],
            "{$prefix}.role_session_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.sts_endpoint" => ['nullable', 'string', 'max:255'],
        ];
    }
}
