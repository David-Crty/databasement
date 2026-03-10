<?php

namespace App\Services\Backup\Compressors;

use App\Services\Backup\ShellProcessor;

abstract class BaseCompressor implements CompressorInterface
{
    public function __construct(
        protected readonly ShellProcessor $shellProcessor,
        private readonly int $level,
        private readonly int $minLevel,
        private readonly int $maxLevel
    ) {}

    public function compress(string $inputPath): string
    {
        $outputPath = $this->getCompressedPath($inputPath);

        // Remove any leftover archive from a previous failed attempt.
        // A corrupted file from a timed-out attempt can cause compressors to
        // fail, hang indefinitely, or prompt for overwrite confirmation.
        if (file_exists($outputPath) && ! unlink($outputPath)) {
            throw new \RuntimeException("Failed to remove stale archive: {$outputPath}");
        }

        $this->shellProcessor->process($this->getCompressCommandLine($inputPath));

        // Some compressors (gzip, zstd --rm) remove the original file automatically,
        // but others (7z) do not. Clean up defensively to ensure consistent behavior.
        if (file_exists($inputPath)) {
            unlink($inputPath);
        }

        return $outputPath;
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

    public function getCompressedPath(string $inputPath): string
    {
        return $inputPath.'.'.$this->getExtension();
    }

    public function getDecompressedPath(string $inputPath): string
    {
        return preg_replace('/\.'.preg_quote($this->getExtension(), '/').'$/', '', $inputPath);
    }

    protected function getLevel(): int
    {
        return max($this->minLevel, min($this->maxLevel, $this->level));
    }
}
