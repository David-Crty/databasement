<?php

namespace App\Actions\Volume;

use App\Enums\VolumeType;
use App\Models\Volume;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateVolumeAction
{
    use AsFake, AsObject;

    private const array STORAGE_LIMIT_KEYS = ['max_storage_bytes', 'max_storage_notify_only'];

    /**
     * Rename a volume and replace its config. A sensitive field left blank
     * keeps its stored value.
     *
     * Once snapshots are stored on the volume they depend on its connection
     * settings, so only the name and the storage limit change from then on.
     *
     * @param  array<string, mixed>  $config  Plain values, including the optional storage limit keys
     */
    public function handle(Volume $volume, string $name, array $config): Volume
    {
        $config = $volume->hasSnapshots()
            ? [...Arr::except($volume->config, self::STORAGE_LIMIT_KEYS), ...Arr::only($config, self::STORAGE_LIMIT_KEYS)]
            : VolumeType::from($volume->type)->encryptSensitiveFields($config, $volume->config);

        $volume->update([
            'name' => $name,
            'config' => $config,
        ]);

        return $volume;
    }
}
