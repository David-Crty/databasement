<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;
use App\Rules\SafePath;

class StoreS3VolumeRequest extends StoreVolumeRequest
{
    protected function volumeType(): VolumeType
    {
        return VolumeType::S3;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configRules(): array
    {
        return [
            'config.bucket' => ['required', 'string', 'max:255'],
            'config.prefix' => ['nullable', 'string', 'max:255', new SafePath],
            'config.region' => ['required', 'string', 'max:255'],
            'config.access_key_id' => ['required_with:config.secret_access_key', 'nullable', 'string', 'max:255'],
            'config.secret_access_key' => ['required_with:config.access_key_id', 'nullable', 'string', 'max:1000'],
            'config.custom_endpoint' => ['nullable', 'string', 'max:255'],
            'config.public_endpoint' => ['nullable', 'string', 'max:255'],
            'config.use_path_style_endpoint' => ['nullable', 'boolean'],
            'config.custom_role_arn' => ['nullable', 'string', 'max:255'],
            'config.role_session_name' => ['nullable', 'string', 'max:255'],
            'config.sts_endpoint' => ['nullable', 'string', 'max:255'],
        ];
    }
}
