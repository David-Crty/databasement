<?php

namespace App\Services\Backup\Compressors;

use App\Enums\CompressionType;
use App\Services\Backup\ShellProcessor;

/**
 * Compressor for AES-256 encrypted backups using 7-Zip.
 */
class EncryptedCompressor extends BaseCompressor
{
    public function __construct(
        ShellProcessor $shellProcessor,
        int $level,
        private readonly ?string $password = null
    ) {
        parent::__construct($shellProcessor, $level, minLevel: 1, maxLevel: 9);
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
        $outputPath = $this->getCompressedPath($inputPath);

        // 7z a -t7z -mx={level} -mhe=on -p{password} output.7z input
        // -mhe=on encrypts headers (file names)
        $command = sprintf('7z a -t7z -mx=%d -mhe=on', $this->getLevel());

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
}
