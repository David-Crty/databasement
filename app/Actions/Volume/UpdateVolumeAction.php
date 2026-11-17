<?php

namespace App\Actions\Volume;

use App\Enums\VolumeType;
use App\Models\Volume;
use App\Services\VolumeConnectionTester;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
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
     * Once snapshots are stored on the volume they depend on its location, so
     * only the name, the storage limit and the credentials change from then
     * on, and new credentials are kept only if they connect.
     *
     * @param  array<string, mixed>  $config  Plain values, including the optional storage limit keys
     *
     * @throws ValidationException when new credentials on a volume holding snapshots fail to connect
     */
    public function handle(Volume $volume, string $name, array $config): Volume
    {
        $type = VolumeType::from($volume->type);

        $config = $volume->hasSnapshots()
            ? $type->encryptSensitiveFields($this->lockedConfig($volume, $type, $config))
            : $type->encryptSensitiveFields($config, $volume->config);

        $volume->update([
            'name' => $name,
            'config' => $config,
        ]);

        return $volume;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function lockedConfig(Volume $volume, VolumeType $type, array $config): array
    {
        $stored = $volume->getDecryptedConfig();
        $credentials = Arr::only($type->mergeSensitiveFromPersisted($config, $stored), $type->credentialFields());

        $locked = [
            ...Arr::except($stored, self::STORAGE_LIMIT_KEYS),
            ...$credentials,
            ...Arr::only($config, self::STORAGE_LIMIT_KEYS),
        ];

        $changed = collect($credentials)->contains(fn (mixed $value, string $field): bool => $value !== ($stored[$field] ?? ''));

        if ($changed) {
            $this->assertConnects($volume, $locked);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertConnects(Volume $volume, array $config): void
    {
        $result = app(VolumeConnectionTester::class)->test(new Volume([
            'name' => $volume->name,
            'type' => $volume->type,
            'config' => $config,
        ]));

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'config' => "The new credentials could not connect to the volume: {$result['message']}",
            ]);
        }
    }
}
