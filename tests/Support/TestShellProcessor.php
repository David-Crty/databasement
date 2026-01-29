<?php

namespace Tests\Support;

use App\Services\Backup\ShellProcessor;

/**
 * Test double for ShellProcessor that captures commands without executing them
 * Useful for testing services that build shell commands
 */
class TestShellProcessor extends ShellProcessor
{
    public array $executedCommands = [];

    public function process(string $command): string
    {
        // Capture the command
        $this->executedCommands[] = $command;

        // Simulate file creation based on command patterns
        $this->simulateCommandEffects($command);

        return 'fake output';
    }

    /**
     * Simulate side effects of commands (creating files)
     */
    private function simulateCommandEffects(string $command): void
    {
        // For mysqldump/pg_dump: extract output path and create fake dump file
        if (preg_match('/>\s*([^\s]+)$/', $command, $matches)) {
            $outputPath = trim($matches[1], '"\'');
            if ($outputPath && $outputPath !== '&1') {
                file_put_contents($outputPath, "-- Fake database dump\nCREATE TABLE test (id INT);\n");
            }
        }

        // For gzip compression: extract input path and create .gz file
        // Matches: gzip -6 '/path/to/file' or gzip '/path/to/file'
        if (preg_match('/^gzip\s+(?:-\d+\s+)?[\'"]?([^\'"]+)[\'"]?$/', $command, $matches)) {
            $inputPath = $matches[1];
            $gzPath = $inputPath.'.gz';
            if (! file_exists($gzPath)) {
                file_put_contents($gzPath, 'fake compressed data');
            }
        }

        // For gzip decompression: extract .gz path and create decompressed file
        // Matches: gzip -d '/path/to/file.gz'
        if (preg_match('/^gzip\s+-d\s+[\'"]?([^\'"]+)[\'"]?$/', $command, $matches)) {
            $gzPath = $matches[1];
            $decompressedPath = preg_replace('/\.gz$/', '', $gzPath);
            file_put_contents($decompressedPath, "-- Fake decompressed data\nCREATE TABLE test (id INT);\n");
        }

        // For zstd compression: extract input path and create .zst file
        // Matches: zstd -3 --rm '/path/to/file'
        if (preg_match('/^zstd\s+-\d+\s+--rm\s+[\'"]?([^\'"]+)[\'"]?$/', $command, $matches)) {
            $inputPath = $matches[1];
            $zstPath = $inputPath.'.zst';
            if (! file_exists($zstPath)) {
                file_put_contents($zstPath, 'fake compressed data');
            }
        }

        // For zstd decompression: extract .zst path and create decompressed file
        // Matches: zstd -d --rm '/path/to/file.zst'
        if (preg_match('/^zstd\s+-d\s+--rm\s+[\'"]?([^\'"]+)[\'"]?$/', $command, $matches)) {
            $zstPath = $matches[1];
            $decompressedPath = preg_replace('/\.zst$/', '', $zstPath);
            file_put_contents($decompressedPath, "-- Fake decompressed data\nCREATE TABLE test (id INT);\n");
        }

        // For 7z encrypted compression: extract output path and create .7z file
        // Matches: 7z a -t7z -mx=6 -mhe=on ... 'output.7z' 'input'
        if (preg_match('/^7z\s+a\s+-t7z\s+-mx=\d+\s+-mhe=on\s+.*?[\'"]?([^\s\'"]+\.7z)[\'"]?\s+/', $command, $matches)) {
            $outputPath = $matches[1];
            if (! file_exists($outputPath)) {
                file_put_contents($outputPath, 'fake 7z compressed data');
            }
        }

        // For 7z extraction: extract archive path and create decompressed file
        // Matches: 7z x -y -o'/path' [-p'password'] '/path/file.ext'
        // 7z extracts to the internal filename (dump.sql or dump.db), not based on archive name
        if (preg_match('/^7z\s+x\s+-y\s+-o[\'"]?([^\s\'"]+)[\'"]?\s+(?:-p[\'"]?[^\s\'"]*[\'"]?\s+)?[\'"]?([^\s\'"]+)[\'"]?$/', $command, $matches)) {
            $outputDir = $matches[1];
            $archivePath = $matches[2];
            // For .db.gz or .db.7z archives, create dump.db
            // For .sql.gz or .7z archives, create dump.sql
            if (str_contains($archivePath, '.db.')) {
                $decompressedPath = rtrim($outputDir, '/').'/dump.db';
            } else {
                $decompressedPath = rtrim($outputDir, '/').'/dump.sql';
            }
            file_put_contents($decompressedPath, "-- Fake decompressed data\nCREATE TABLE test (id INT);\n");
        }
    }

    /**
     * Get all executed commands
     */
    public function getCommands(): array
    {
        return $this->executedCommands;
    }

    public function hasCommand($command): bool
    {
        return in_array($command, $this->executedCommands);
    }

    /**
     * Clear all captured commands
     */
    public function clearCommands(): void
    {
        $this->executedCommands = [];
    }
}
