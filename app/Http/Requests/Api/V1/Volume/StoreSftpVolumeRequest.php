<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;
use App\Rules\SafePath;

class StoreSftpVolumeRequest extends StoreVolumeRequest
{
    protected function volumeType(): VolumeType
    {
        return VolumeType::SFTP;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configRules(): array
    {
        return [
            'config.host' => ['required', 'string', 'max:255'],
            'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'config.username' => ['required', 'string', 'max:255'],
            'config.password' => ['required', 'string', 'max:1000'],
            'config.root' => ['nullable', 'string', 'max:500', new SafePath(allowAbsolute: true)],
            'config.timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
        ];
    }
}
