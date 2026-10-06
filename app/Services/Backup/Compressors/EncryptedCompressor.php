<?php

namespace App\Services\Backup\Compressors;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Services\Backup\ShellProcessor;

/**
 * Compressor for AES-256 encrypted backups using 7-Zip.
 */
class EncryptedCompressor extends BaseCompressor
{
    public function __construct(
        ShellProcessor $shellProcessor,
        int $level,
        private readonly ?string $password = null,
        bool $multithread = false
    ) {
        parent::__construct($shellProcessor, $level, minLevel: 1, maxLevel: 9, multithread: $multithread);
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

        // -mmt=on spreads compression across all available CPU cores
        if ($this->isMultithreaded()) {
            $command .= ' -mmt=on';
        }

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

        // -mmt=on spreads decompression across all available CPU cores
        if ($this->isMultithreaded()) {
            $command .= ' -mmt=on';
        }

        if ($this->password !== null) {
            $command .= sprintf(' -p%s', escapeshellarg($this->password));
        }

        $command .= sprintf(' %s', escapeshellarg($outputPath));

        return $command;
    }

    /**
     * 7-Zip restores the archived file under its original basename, so the
     * extracted dump is always `dump.<extension>` (see BackupTask). The
     * extension depends on the database type and dump format, neither of which
     * is known here, so every extension the enum can produce is accepted.
     */
    public function getDecompressedPath(string $inputPath): string
    {
        $targets = array_map(
            static fn (string $extension): string => 'dump.'.$extension,
            DatabaseType::dumpExtensions(),
        );

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
