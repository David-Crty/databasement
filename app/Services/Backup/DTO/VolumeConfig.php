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

    public static function fromVolume(Volume $volume): self
    {
        return new self(
            type: $volume->type,
            name: $volume->name,
            config: $volume->getDecryptedConfig(),
        );
    }
}
