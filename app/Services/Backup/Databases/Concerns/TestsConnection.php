<?php

namespace App\Services\Backup\Databases\Concerns;

use App\Support\Formatters;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Builds the {@see \App\Services\Backup\Databases\DatabaseInterface::testConnection()}
 * result, for handlers that probe through a client command or a driver.
 */
trait TestsConnection
{
    /**
     * Run a client command that only succeeds once connected. A timeout or a
     * non-zero exit is the failure; otherwise $onSuccess builds the result,
     * which defaults to the command's trimmed output.
     *
     * @param  string|array<int, string>  $command
     * @param  (\Closure(ProcessResult, int): array{success: bool, message: string, details: array<string, mixed>})|null  $onSuccess
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    protected function probeConnection(string|array $command, ?\Closure $onSuccess = null, ?string $input = null): array
    {
        $startTime = microtime(true);

        try {
            $result = Process::timeout(10)->input($input)->run($command);
        } catch (ProcessTimedOutException) {
            return $this->connectionTimedOut(Formatters::elapsedMs($startTime));
        }

        $durationMs = Formatters::elapsedMs($startTime);

        if ($result->failed()) {
            $errorOutput = trim($result->errorOutput() ?: $result->output());

            return $this->connectionFailed($errorOutput ?: 'Connection failed with exit code '.$result->exitCode());
        }

        return $onSuccess !== null
            ? $onSuccess($result, $durationMs)
            : $this->connectionSucceeded($durationMs, trim($result->output()));
    }

    /**
     * Failure from a driver exception. The drivers give up at a 10 second
     * connect timeout without saying so, which is reported as a timeout.
     *
     * @return array{success: false, message: string, details: array{}}
     */
    protected function driverConnectionFailure(\Throwable $exception, float $startTime): array
    {
        $durationMs = Formatters::elapsedMs($startTime);

        return $durationMs >= 9500
            ? $this->connectionTimedOut($durationMs)
            : $this->connectionFailed($exception->getMessage());
    }

    /**
     * @return array{success: false, message: string, details: array{}}
     */
    protected function connectionFailed(string $message): array
    {
        return ['success' => false, 'message' => $message, 'details' => []];
    }

    /**
     * @return array{success: true, message: string, details: array{ping_ms: int, output: string}}
     */
    protected function connectionSucceeded(int $durationMs, string $output): array
    {
        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => $output,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $serverInfo
     * @return array{success: true, message: string, details: array{ping_ms: int, output: string}}
     */
    protected function connectionSucceededWithInfo(int $durationMs, array $serverInfo): array
    {
        return $this->connectionSucceeded(
            $durationMs,
            json_encode($serverInfo, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{success: false, message: string, details: array{}}
     */
    private function connectionTimedOut(int $durationMs): array
    {
        return $this->connectionFailed('Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.');
    }
}
