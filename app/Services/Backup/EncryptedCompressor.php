<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;

/**
 * Compressor for AES-256 encrypted backups using 7-Zip.
 */
class EncryptedCompressor implements CompressorInterface
{
    private const MIN_LEVEL = 1;

    private const MAX_LEVEL = 9;

    public function __construct(
        private readonly ShellProcessor $shellProcessor,
        private readonly int $level,
        private readonly ?string $password = null
    ) {}

    public function compress(string $inputPath): string
    {
        $this->shellProcessor->process($this->getCompressCommandLine($inputPath));

        // 7z doesn't remove the original file, so we do it manually
        if (file_exists($inputPath)) {
            unlink($inputPath);
        }

        return $this->getCompressedPath($inputPath);
    }

    public function decompress(string $compressedFile): string
    {
        $outputDir = dirname($compressedFile);

        $this->shellProcessor->process($this->getDecompressCommandLine($compressedFile));

        return $this->getDecompressedPath($outputDir);
    }

    public function getExtension(): string
    {
        return CompressionType::ENCRYPTED->extension();
    }

    public function getCompressCommandLine(string $inputPath): string
    {
        $level = $this->getLevel();
        $outputPath = $this->getCompressedPath($inputPath);

        // 7z a -t7z -mx={level} -mhe=on -p{password} output.7z input
        // -mhe=on encrypts headers (file names)
        $command = sprintf('7z a -t7z -mx=%d -mhe=on', $level);

        if ($this->password !== null) {
            $command .= sprintf(' -p%s', escapeshellarg($this->password));
        }

        $command .= sprintf(' %s %s', escapeshellarg($outputPath), escapeshellarg($inputPath));

        return $command;
    }

    public function getDecompressCommandLine(string $outputPath): string
    {
        $outputDir = dirname($outputPath);

        // 7z x -y -o{dir} [-p{password}] archive
        // -y: assume Yes on all queries (overwrite files)
        $command = sprintf('7z x -y -o%s', escapeshellarg($outputDir));

        if ($this->password !== null) {
            $command .= sprintf(' -p%s', escapeshellarg($this->password));
        }

        $command .= sprintf(' %s', escapeshellarg($outputPath));

        return $command;
    }

    public function getCompressedPath(string $inputPath): string
    {
        return $inputPath.'.'.$this->getExtension();
    }

    public function getDecompressedPath(string $inputPath): string
    {
        $targets = ['dump.sql', 'dump.db'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($inputPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getFilename(), $targets, true)) {
                return $file->getPathname();
            }
        }

        throw new \RuntimeException('Decompression failed: output file not found');
    }

    private function getLevel(): int
    {
        return max(self::MIN_LEVEL, min(self::MAX_LEVEL, $this->level));
    }
}
