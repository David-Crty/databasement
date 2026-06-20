<?php

namespace App\Services\Backup\DTO;

use App\Models\Volume;

readonly class VolumeConfig
{
    /**
     * @param  array<string, mixed>  $config  Decrypted volume config
     */
    public function __construct(
        public string $type,
        public string $name,
        public array $config,
    ) {}

    /**
     * @return array{type: string, name: string, config: array<string, mixed>}
     */
    public function toPayload(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'config' => $this->config,
        ];
    }

    public static function fromVolume(Volume $volume): self
    {
        return new self(
            type: $volume->type,
            name: $volume->name,
            config: $volume->getDecryptedConfig(),
        );
    }

    /**
     * @param  array{type: string, name?: string, config?: array<string, mixed>}  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            type: $payload['type'],
            name: $payload['name'] ?? 'Remote Volume',
            config: $payload['config'] ?? [],
        );
    }
}
