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

    /**
     * Highest compression level accepted by the underlying tool.
     * gzip and 7z cap at 9; zstd goes up to 19.
     */
    public function maxLevel(): int
    {
        return match ($this) {
            self::GZIP, self::ENCRYPTED => 9,
            self::ZSTD => 19,
        };
    }

    /**
     * Whether the underlying tool can spread compression across CPU cores.
     * zstd (-T0) and 7z (-mmt) do; standard gzip is single-threaded.
     */
    public function supportsMultithreading(): bool
    {
        return match ($this) {
            self::ZSTD, self::ENCRYPTED => true,
            self::GZIP => false,
        };
    }
}
