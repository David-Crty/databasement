<?php

namespace App\Http\Requests\Api\V1\Volume;

use App\Enums\VolumeType;

class StoreSftpVolumeRequest extends StoreVolumeRequest
{
    protected function volumeType(): VolumeType
    {
        return VolumeType::SFTP;
    }
}
