<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;

class GzipCompressor implements CompressorInterface
{
    private const MIN_LEVEL = 1;

    private const MAX_LEVEL = 9;

    public function __construct(
        private readonly ShellProcessor $shellProcessor,
        private readonly int $level
    ) {}

    public function compress(string $inputPath): string
    {
        $this->shellProcessor->process($this->getCompressCommandLine($inputPath));

        return $this->getCompressedPath($inputPath);
    }

    public function decompress(string $compressedFile): string
    {
        $this->shellProcessor->process($this->getDecompressCommandLine($compressedFile));

        // gzip -d removes .gz suffix from the file
        $decompressedFile = $this->getDecompressedPath($compressedFile);

        if (! file_exists($decompressedFile)) {
            throw new \RuntimeException('Decompression failed: output file not found');
        }

        return $decompressedFile;
    }

    public function getExtension(): string
    {
        return CompressionType::GZIP->extension();
    }

    public function getCompressCommandLine(string $inputPath): string
    {
        $level = $this->getLevel();

        return sprintf('gzip -%d %s', $level, escapeshellarg($inputPath));
    }

    public function getDecompressCommandLine(string $outputPath): string
    {
        return 'gzip -d '.escapeshellarg($outputPath);
    }

    public function getCompressedPath(string $inputPath): string
    {
        return $inputPath.'.'.$this->getExtension();
    }

    public function getDecompressedPath(string $inputPath): string
    {
        return preg_replace('/\.gz$/', '', $inputPath);
    }

    private function getLevel(): int
    {
        return max(self::MIN_LEVEL, min(self::MAX_LEVEL, $this->level));
    }
}
