<?php

namespace App\Services\Backup\Compressors;

use App\Enums\CompressionType;
use App\Services\Backup\ShellProcessor;

class ZstdCompressor extends BaseCompressor
{
    public function __construct(ShellProcessor $shellProcessor, int $level, bool $multithread = false)
    {
        parent::__construct($shellProcessor, $level, minLevel: 1, maxLevel: 19, multithread: $multithread);
    }

    public function getExtension(): string
    {
        return CompressionType::ZSTD->extension();
    }

    public function getCompressCommandLine(string $inputPath): string
    {
        // --rm removes the original file after compression (like gzip does by default)
        // -T0 spreads compression across all available CPU cores
        $threads = $this->isMultithreaded() ? ' -T0' : '';

        return sprintf('zstd -%d%s --rm %s', $this->getLevel(), $threads, escapeshellarg($inputPath));
    }

    public function getDecompressCommandLine(string $outputPath): string
    {
        // -d decompress, --rm removes the compressed file after decompression
        return sprintf('zstd -d --rm %s', escapeshellarg($outputPath));
    }
}
