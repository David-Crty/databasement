<?php

namespace App\Services\Backup\DTO;

use App\Models\Volume;

readonly class VolumeConfig
{
    /**
     * @param  array<string, mixed>  $config  Decrypted volume config
     * @param  string|null  $id  App-side Volume ULID. Serialized in agent payloads so
     *                           per-volume upload results can be matched back to
     *                           snapshot file rows. Null for legacy payloads.
     * @param  int|null  $usedBytes  Bytes currently stored on this volume. Set app-side
     *                               so the upload can be pre-checked against the volume's
     *                               storage limit. Null (e.g. remote agents, which cannot
     *                               read the app database) skips the check. Not serialized.
     */
    public function __construct(
        public string $type,
        public string $name,
        public array $config,
        public ?string $id = null,
        public ?int $usedBytes = null,
    ) {}

    /**
     * @return array{id: string|null, type: string, name: string, config: array<string, mixed>}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'config' => $this->config,
        ];
    }

    public static function fromVolume(Volume $volume, ?int $usedBytes = null): self
    {
        return new self(
            type: $volume->type,
            name: $volume->name,
            config: $volume->getDecryptedConfig(),
            id: $volume->id,
            usedBytes: $usedBytes,
        );
    }

    /**
     * @param  array{id?: string|null, type: string, name?: string, config?: array<string, mixed>}  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            type: $payload['type'],
            name: $payload['name'] ?? 'Remote Volume',
            config: $payload['config'] ?? [],
            id: $payload['id'] ?? null,
        );
    }
}
