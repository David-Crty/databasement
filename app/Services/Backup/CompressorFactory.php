<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;

class CompressorFactory
{
    public function __construct(
        private readonly ShellProcessor $shellProcessor
    ) {}

    /**
     * Create a compressor instance based on configuration.
     */
    public function make(?CompressionType $type = null, ?int $level = null): CompressorInterface
    {
        $type = $type ?? CompressionType::from(config('backup.compression'));
        $level = $level ?? (int) config('backup.compression_level');

        return match ($type) {
            CompressionType::GZIP => new GzipCompressor($this->shellProcessor, $level),
            CompressionType::ZSTD => new ZstdCompressor($this->shellProcessor, $level),
        };
    }
}
