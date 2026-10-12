<?php

namespace App\Actions\Volume;

use App\Enums\VolumeType;
use App\Models\Organization;
use App\Models\Volume;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateVolumeAction
{
    use AsFake, AsObject;

    /**
     * Create a volume, encrypting the sensitive fields of its config.
     *
     * @param  array<string, mixed>  $config  Plain values, including the optional storage limit keys
     */
    public function handle(Organization $organization, VolumeType $type, string $name, array $config): Volume
    {
        return Volume::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'type' => $type->value,
            'config' => $type->encryptSensitiveFields($config),
        ]);
    }
}
