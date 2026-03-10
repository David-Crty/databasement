<?php

namespace App\Services\Backup\Compressors;

use App\Enums\CompressionType;
use App\Services\Backup\ShellProcessor;

class GzipCompressor extends BaseCompressor
{
    public function __construct(ShellProcessor $shellProcessor, int $level)
    {
        parent::__construct($shellProcessor, $level, minLevel: 1, maxLevel: 9);
    }

    public function getExtension(): string
    {
        return CompressionType::GZIP->extension();
    }

    public function getCompressCommandLine(string $inputPath): string
    {
        return sprintf('gzip -%d %s', $this->getLevel(), escapeshellarg($inputPath));
    }

    public function getDecompressCommandLine(string $outputPath): string
    {
        return 'gzip -d '.escapeshellarg($outputPath);
    }
}
