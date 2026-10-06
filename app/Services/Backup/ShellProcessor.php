<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\JobRevokedException;
use App\Exceptions\ShellProcessFailed;
use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class ShellProcessor
{
    private const int SIGKILL = 9;

    private ?BackupLogger $logger = null;

    /**
     * @param  int  $outputHeadBytes  Leading slice of a command's output to keep.
     * @param  int  $outputTailBytes  Trailing slice of a command's output to keep.
     * @param  float  $progressIntervalSeconds  Delay between reports of a running command's output.
     */
    public function __construct(
        private readonly int $outputHeadBytes = 16384,
        private readonly int $outputTailBytes = 16384,
        private readonly float $progressIntervalSeconds = 30.0,
    ) {}

    public function setLogger(BackupLogger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Run a command, reporting it through the logger when it starts, every
     * {@see $progressIntervalSeconds} while it runs (its output so far, which
     * is also what tells the server the command is alive), and when it ends.
     *
     * A failed report never stops the command, except a
     * {@see JobRevokedException}: the job is no longer this runner's to run.
     *
     * @param  array<string, string>  $env  Extra environment variables exposed to the command.
     */
    public function process(string $command, array $env = []): string
    {
        // Its own process group, so that stopping it also stops what it
        // started, such as the commands of a post-backup script.
        $process = Process::fromShellCommandline('exec setsid sh -c '.escapeshellarg($command));
        $process->setTimeout(null);

        if ($env !== []) {
            $process->setEnv($env);
        }

        // Mask sensitive data in command line for logging
        $sanitizedCommand = $this->sanitize($command);
        $startTime = microtime(true);

        // The buffer bounds what reaches the stored log, so a command emitting
        // an unbounded number of warnings cannot grow the job's `logs` blob. It
        // does not bound memory: Process keeps the full output internally for
        // getOutput()/getErrorOutput() below.
        $output = $this->newOutputBuffer();
        $receivedOutput = false;
        $logIndex = $this->reportSafely(fn () => $this->logger?->startCommandLog($sanitizedCommand));

        try {
            $process->start(function ($type, $data) use ($output, &$receivedOutput) {
                $output->append($data);
                $receivedOutput = true;
            });
            $lastReport = microtime(true);

            while ($process->isRunning()) {
                if (microtime(true) - $lastReport >= $this->progressIntervalSeconds) {
                    $this->reportSafely(fn () => $this->reportCommand($logIndex, $output, $startTime));
                    $lastReport = microtime(true);
                }

                // Polling reads the pipes without blocking. Pausing only after an
                // empty poll, and briefly, keeps a command that streams its output
                // through PHP from stalling on a full pipe.
                if (! $receivedOutput) {
                    usleep(20_000);
                }

                $receivedOutput = false;
            }

            $process->wait();
        } catch (Throwable $e) {
            $this->stop($process);
            rescue(fn () => $this->reportCommand($logIndex, $output, $startTime, ['status' => 'failed']), report: false);

            throw $e;
        }

        $errorOutput = $process->getErrorOutput();

        $finalOutput = $this->newOutputBuffer();
        $finalOutput->append($process->getOutput());
        $finalOutput->append("\n");
        $finalOutput->append($errorOutput);

        $this->reportSafely(fn () => $this->reportCommand($logIndex, $finalOutput, $startTime, [
            'exit_code' => $process->getExitCode(),
            'status' => $process->isSuccessful() ? 'completed' : 'failed',
        ]));

        if (! $process->isSuccessful()) {
            // Bounded as well: this message ends up in `backup_jobs.error_message`,
            // a TEXT column that a multi-megabyte stderr would overflow.
            $error = $this->newOutputBuffer();
            $error->append($errorOutput);

            $sanitizedError = $this->sanitize($error->toString());
            Log::error($sanitizedCommand."\n".$sanitizedError);
            throw new ShellProcessFailed($sanitizedError);
        }

        return $process->getOutput();
    }

    private function stop(Process $process): void
    {
        $pid = $process->getPid();

        if ($pid !== null && $process->isRunning()) {
            posix_kill(-$pid, self::SIGKILL);
        }

        $process->stop(0);
    }

    /**
     * Report a command's output so far, plus any $data, to its log entry.
     *
     * @param  array<string, mixed>  $data
     */
    private function reportCommand(?int $logIndex, OutputBuffer $output, float $startTime, array $data = []): void
    {
        if ($this->logger !== null && $logIndex !== null) {
            $this->logger->updateCommandLog($logIndex, [
                'output' => $this->sanitize(trim($output->toString())),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ...$data,
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $report
     * @return T|null
     */
    private function reportSafely(Closure $report): mixed
    {
        try {
            return $report();
        } catch (JobRevokedException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning("Could not report a running command: {$e->getMessage()}");

            return null;
        }
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
            // redis-cli --pass VALUE. The `-a` alias is deliberately not matched:
            // `-a` is a real flag on rsync, tar and 7z, so a pattern for it would
            // redact unrelated commands. RedisDatabase emits `--pass` for this reason.
            '#--pass\s+(?:\'[^\']*\'|"[^"]*"|[^\s]+)#' => '--pass ***',
            // MongoDB connection URI userinfo: mongodb://user:PASS@host or mongodb+srv://user:PASS@host
            '#(mongodb(?:\+srv)?://[^:@\s/]+:)[^@\s]+@#' => '$1***@',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $input = preg_replace($pattern, $replacement, $input);
        }

        return $input;
    }
}
