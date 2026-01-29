<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;

class ZstdCompressor implements CompressorInterface
{
    private const MIN_LEVEL = 1;

    private const MAX_LEVEL = 19;

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

        $decompressedFile = $this->getDecompressedPath($compressedFile);

        if (! file_exists($decompressedFile)) {
            throw new \RuntimeException('Decompression failed: output file not found');
        }

        return $decompressedFile;
    }

    public function getExtension(): string
    {
        return CompressionType::ZSTD->extension();
    }

    public function getCompressCommandLine(string $inputPath): string
    {
        $level = $this->getLevel();

        // --rm removes the original file after compression (like gzip does by default)
        return sprintf('zstd -%d --rm %s', $level, escapeshellarg($inputPath));
    }

    public function getDecompressCommandLine(string $outputPath): string
    {
        // -d decompress, --rm removes the compressed file after decompression
        return sprintf('zstd -d --rm %s', escapeshellarg($outputPath));
    }

    public function getCompressedPath(string $inputPath): string
    {
        return $inputPath.'.'.$this->getExtension();
    }

    public function getDecompressedPath(string $inputPath): string
    {
        return preg_replace('/\.'.preg_quote($this->getExtension(), '/').'$/', '', $inputPath);
    }

    private function getLevel(): int
    {
        return max(self::MIN_LEVEL, min(self::MAX_LEVEL, $this->level));
    }
}
