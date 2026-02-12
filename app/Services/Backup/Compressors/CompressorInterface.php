<?php

namespace App\Services\Backup\Compressors;

interface CompressorInterface
{
    /**
     * Compress a file and return the path to the compressed file.
     */
    public function compress(string $inputPath): string;

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
