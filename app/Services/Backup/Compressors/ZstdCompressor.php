<?php

namespace App\Services\Backup\Compressors;

use App\Enums\CompressionType;
use App\Services\Backup\ShellProcessor;

class ZstdCompressor extends BaseCompressor
{
    public function __construct(ShellProcessor $shellProcessor, int $level)
    {
        parent::__construct($shellProcessor, $level, minLevel: 1, maxLevel: 19);
    }

    public function getExtension(): string
    {
        return CompressionType::ZSTD->extension();
    }

    public function getCompressCommandLine(string $inputPath): string
    {
        // --rm removes the original file after compression (like gzip does by default)
        return sprintf('zstd -%d --rm %s', $this->getLevel(), escapeshellarg($inputPath));
    }

    public function getDecompressCommandLine(string $outputPath): string
    {
        // -d decompress, --rm removes the compressed file after decompression
        return sprintf('zstd -d --rm %s', escapeshellarg($outputPath));
    }
}
