<?php

namespace Tests\Support;

use App\Services\Backup\ShellProcessor;
use Symfony\Component\Process\Process;

/**
 * Test double for ShellProcessor that captures commands without executing them
 * Useful for testing services that build shell commands
 */
class TestShellProcessor extends ShellProcessor
{
    public array $executedCommands = [];

    public function process(Process $process): string
    {
        $commandLine = $process->getCommandLine();

        // Capture the command
        $this->executedCommands[] = $commandLine;

        // Simulate file creation based on command patterns
        $this->simulateCommandEffects($commandLine);

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
        // Matches: gzip '/path/to/file'
        if (preg_match('/^gzip\s+[\'"]?([^\'"]+)[\'"]?$/', $command, $matches)) {
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
    }

    /**
     * Sanitize commands (copied from parent for logging)
     */
    private function sanitizeCommand(string $command): string
    {
        $patterns = [
            '/--password=[^\s]+/' => '--password=***',
            '/-p[^\s]+/' => '-p***',
            '/PGPASSWORD=[^\s]+/' => 'PGPASSWORD=***',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $command = preg_replace($pattern, $replacement, $command);
        }

        return $command;
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
}
