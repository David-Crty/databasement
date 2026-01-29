<?php

namespace App\Enums;

enum CompressionType: string
{
    case GZIP = 'gzip';
    case ZSTD = 'zstd';
    case ENCRYPTED = 'encrypted';

    public function extension(): string
    {
        return match ($this) {
            self::GZIP => 'gz',
            self::ZSTD => 'zst',
            self::ENCRYPTED => '7z',
        };
    }
}
