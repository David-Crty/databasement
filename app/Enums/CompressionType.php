<?php

namespace App\Enums;

enum CompressionType: string
{
    case GZIP = 'gzip';
    case ZSTD = 'zstd';
    case ENCRYPTED = 'encrypted';

    public function label(): string
    {
        return match ($this) {
            self::GZIP => 'Gzip',
            self::ZSTD => 'Zstd',
            self::ENCRYPTED => 'Encrypted',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GZIP, self::ZSTD => 'o-archive-box',
            self::ENCRYPTED => 'o-lock-closed',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::GZIP => 'gz',
            self::ZSTD => 'zst',
            self::ENCRYPTED => '7z',
        };
    }
}
