<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;

class StoreS3VolumeRequest extends StoreVolumeRequest
{
    protected function volumeType(): VolumeType
    {
        return VolumeType::S3;
    }
}
