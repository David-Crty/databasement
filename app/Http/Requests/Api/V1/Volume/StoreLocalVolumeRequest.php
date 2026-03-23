<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;
use App\Rules\SafePath;

class StoreLocalVolumeRequest extends StoreVolumeRequest
{
    protected function volumeType(): VolumeType
    {
        return VolumeType::LOCAL;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configRules(): array
    {
        return [
            'config.path' => ['required', 'string', 'max:500', new SafePath(allowAbsolute: true)],
        ];
    }
}
