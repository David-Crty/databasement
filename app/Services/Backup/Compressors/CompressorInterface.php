<?php

namespace App\Services\Backup\Compressors;

interface CompressorInterface
{
    /**
     * Compress a file and return the path to the compressed file.
     */
    public function compress(string $inputPath): string;

    /**
     * Compress the stdout of a shell command into the archive for $inputPath,
     * without writing the uncompressed dump to disk, and return the archive path.
     */
    public function compressCommandOutput(string $command, string $inputPath): string;

    /**
     * Decompress a file and return the path to the decompressed file.
     */
    public function decompress(string $compressedFile): string;

    /**
     * Get the file extension for compressed files (e.g., 'gz', 'zst').
     */
    public function getExtension(): string;

    /**
     * Get the command line for compressing a file.
     */
    public function getCompressCommandLine(string $inputPath): string;

    /**
     * Get the command line that compresses stdin into the archive for $inputPath.
     */
    public function getCompressStdinCommandLine(string $inputPath): string;

    /**
     * Get the command line for decompressing a file.
     */
    public function getDecompressCommandLine(string $outputPath): string;

    /**
     * Get the path to the compressed file given an input path.
     */
    public function getCompressedPath(string $inputPath): string;

    /**
     * Get the path to the decompressed file given a compressed path.
     */
    public function getDecompressedPath(string $inputPath): string;
}
