<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Exceptions\ShellProcessFailed;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ShellProcessor
{
    private ?BackupLogger $logger = null;

    /**
     * The flush interval matters as much as the output budget: every incremental
     * write re-serializes the job's whole `logs` JSON blob, so flushing once per
     * output chunk makes a chatty command quadratic in database writes.
     *
     * @param  int  $outputHeadBytes  Leading slice of a command's output to keep.
     * @param  int  $outputTailBytes  Trailing slice of a command's output to keep.
     * @param  float  $flushIntervalSeconds  Minimum delay between incremental log writes.
     */
    public function __construct(
        private readonly int $outputHeadBytes = 16384,
        private readonly int $outputTailBytes = 16384,
        private readonly float $flushIntervalSeconds = 1.0,
    ) {}

    public function setLogger(BackupLogger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param  array<string, string>  $env  Extra environment variables exposed to the command.
     */
    public function process(string $command, array $env = []): string
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null);

        if ($env !== []) {
            $process->setEnv($env);
        }

        // Mask sensitive data in command line for logging
        $sanitizedCommand = $this->sanitize($command);
        $startTime = microtime(true);

        // Start the command log entry before execution
        $logIndex = $this->logger?->startCommandLog($sanitizedCommand);

        // Run with output callback for incremental updates. The buffer bounds what
        // reaches the stored log, so a command emitting an unbounded number of
        // warnings cannot grow the job's `logs` blob. It does not bound memory:
        // Process keeps the full output internally for getOutput()/getErrorOutput()
        // below, so a huge stream still accumulates for the command's lifetime.
        $incrementalOutput = $this->newOutputBuffer();
        $lastFlush = 0.0;

        $process->run(function ($type, $data) use ($incrementalOutput, &$lastFlush, $logIndex, $startTime) {
            $incrementalOutput->append($data);

            if (! $this->logger || $logIndex === null) {
                return;
            }

            // Throttle incremental updates; the final write below always runs, so
            // nothing is lost by skipping a flush here.
            $now = microtime(true);

            if ($now - $lastFlush < $this->flushIntervalSeconds) {
                return;
            }

            $lastFlush = $now;

            $this->logger->updateCommandLog($logIndex, [
                'output' => $this->sanitize(trim($incrementalOutput->toString())),
                'duration_ms' => round(($now - $startTime) * 1000, 2),
            ]);
        });

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Finalize the log entry with exit code and status
        if ($this->logger && $logIndex !== null) {
            $finalOutput = $this->newOutputBuffer();
            $finalOutput->append($output);
            $finalOutput->append("\n");
            $finalOutput->append($errorOutput);

            $this->logger->updateCommandLog($logIndex, [
                'output' => $this->sanitize(trim($finalOutput->toString())),
                'exit_code' => $exitCode,
                'status' => $process->isSuccessful() ? 'completed' : 'failed',
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        }

        if (! $process->isSuccessful()) {
            // Bounded as well: this message ends up in `backup_jobs.error_message`,
            // a TEXT column that a multi-megabyte stderr would overflow.
            $error = $this->newOutputBuffer();
            $error->append($errorOutput);

            $sanitizedError = $this->sanitize($error->toString());
            Log::error($sanitizedCommand."\n".$sanitizedError);
            throw new ShellProcessFailed($sanitizedError);
        }

        return $output;
    }

    private function newOutputBuffer(): OutputBuffer
    {
        return new OutputBuffer($this->outputHeadBytes, $this->outputTailBytes);
    }

    /**
     * Sanitize sensitive data from commands or output before logging or throwing exceptions.
     *
     * This method redacts passwords that may appear in shell commands or their error output.
     */
    public function sanitize(string $input): string
    {
        $patterns = [
            // Match --password=VALUE or --password='VALUE' or --password="VALUE"
            '/--password=[\'"]?[^\s\'"]+[\'"]?/' => '--password=***',
            // Match Firebird-style -password VALUE (single-quoted, double-quoted, or unquoted token)
            '#-password\s+(?:\'[^\']*\'|"[^"]*"|[^\s]+)#' => '-password ***',
            // Match -pPASSWORD (MySQL shorthand) - only when -p is a standalone argument
            // followed directly by password (not --port, not -password, not inside words like mysql-production)
            '/(^|\s)-p(?!assword\b)([^\s\-][^\s]*)/' => '$1-p***',
            // Match PGPASSWORD=VALUE
            '/PGPASSWORD=[^\s]+/' => 'PGPASSWORD=***',
            // Match MYSQL_PWD=VALUE
            '/MYSQL_PWD=[^\s]+/' => 'MYSQL_PWD=***',
            // Match 7z password: -p'password' or -p"password" or -ppassword
            "/-p'[^']*'/" => '-p***',
            '/-p"[^"]*"/' => '-p***',
            // SSH password via sshpass: sshpass -p 'password' or sshpass -p "password" or sshpass -p password
            '/sshpass\s+-p\s+[\'"]?[^\s\'"]+[\'"]?/' => 'sshpass -p ***',
            // SSH_ASKPASS scripts may contain passphrases
            '/SSH_ASKPASS=[^\s]+/' => 'SSH_ASKPASS=***',
            // sqlpackage: /SourcePassword:'...' or /TargetPassword:'...' (escapeshellarg single-quotes the value)
            '#/(Source|Target)Password:(?:\'[^\']*\'|"[^"]*"|[^\s]+)#' => '/$1Password:***',
            // MongoDB connection URI userinfo: mongodb://user:PASS@host or mongodb+srv://user:PASS@host
            '#(mongodb(?:\+srv)?://[^:@\s/]+:)[^@\s]+@#' => '$1***@',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $input = preg_replace($pattern, $replacement, $input);
        }

        return $input;
    }
}
