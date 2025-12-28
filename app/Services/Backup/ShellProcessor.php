<?php

namespace App\Services\Backup;

use App\Exceptions\ShellProcessFailed;
use App\Models\BackupJob;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ShellProcessor
{
    private ?BackupJob $logger = null;

    public function setLogger(BackupJob $logger): void
    {
        $this->logger = $logger;
    }

    public function process(string $command): string
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null);

        // Mask sensitive data in command line for logging
        $sanitizedCommand = $this->sanitizeCommand($command);
        $startTime = microtime(true);

        // Start the command log entry before execution
        $logIndex = null;
        if ($this->logger) {
            $logIndex = $this->logger->startCommandLog($sanitizedCommand);
        }

        // Run with output callback for incremental updates
        $incrementalOutput = '';
        $process->run(function ($type, $data) use (&$incrementalOutput, $logIndex, $startTime) {
            $incrementalOutput .= $data;

            // Update the log entry with incremental output
            if ($this->logger && $logIndex !== null) {
                $this->logger->updateCommandLog($logIndex, [
                    'output' => trim($incrementalOutput),
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);
            }
        });

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Finalize the log entry with exit code and status
        if ($this->logger && $logIndex !== null) {
            $combinedOutput = trim($output."\n".$errorOutput);
            $this->logger->updateCommandLog($logIndex, [
                'output' => $combinedOutput,
                'exit_code' => $exitCode,
                'status' => $process->isSuccessful() ? 'completed' : 'failed',
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        }

        if (! $process->isSuccessful()) {
            Log::error($command."\n".$errorOutput);
            throw new ShellProcessFailed($errorOutput);
        }

        return $output;
    }

    public function sanitizeCommand(string $command): string
    {
        // Mask passwords in MySQL/PostgreSQL commands
        $patterns = [
            // Match --password=VALUE or --password='VALUE' or --password="VALUE"
            '/--password=[\'"]?[^\s\'"]+[\'"]?/' => '--password=***',
            // Match -pPASSWORD (MySQL shorthand) - only when -p is a standalone argument
            // followed directly by password (not --port, not inside words like mysql-production)
            '/(^|\s)-p([^\s\-][^\s]*)/' => '$1-p***',
            // Match PGPASSWORD=VALUE
            '/PGPASSWORD=[^\s]+/' => 'PGPASSWORD=***',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $command = preg_replace($pattern, $replacement, $command);
        }

        return $command;
    }
}
