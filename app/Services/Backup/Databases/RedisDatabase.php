<?php

namespace App\Services\Backup\Databases;

use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Models\BackupJob;
use App\Support\Formatters;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class RedisDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getDumpCommandLine(string $outputPath): string
    {
        $parts = $this->buildBaseCommand();
        $parts[] = '--rdb '.escapeshellarg($outputPath);

        return implode(' ', $parts);
    }

    public function getRestoreCommandLine(string $inputPath): string
    {
        throw new UnsupportedDatabaseTypeException('redis');
    }

    public function prepareForRestore(string $schemaName, BackupJob $job): void
    {
        throw new UnsupportedDatabaseTypeException('redis');
    }

    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $pingResult = Process::timeout(10)->run($this->buildPingCommand());
        } catch (ProcessTimedOutException) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
                'details' => [],
            ];
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($pingResult->failed()) {
            $errorOutput = trim($pingResult->errorOutput() ?: $pingResult->output());

            return [
                'success' => false,
                'message' => $errorOutput ?: 'Connection failed with exit code '.$pingResult->exitCode(),
                'details' => [],
            ];
        }

        if (! str_contains($pingResult->output(), 'PONG')) {
            return ['success' => false, 'message' => 'Unexpected response from Redis server: '.trim($pingResult->output()), 'details' => []];
        }

        $serverInfo = [];

        try {
            $infoResult = Process::timeout(10)->run($this->buildInfoCommand());
            if ($infoResult->successful()) {
                foreach (explode("\n", $infoResult->output()) as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'redis_version:') || str_starts_with($line, 'used_memory_human:') || str_starts_with($line, 'os:')) {
                        [$key, $value] = explode(':', $line, 2);
                        $serverInfo[$key] = $value;
                    }
                }
            }
        } catch (ProcessTimedOutException) {
            // Non-critical — server info is optional
        }

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => json_encode(array_merge(['dbms' => 'Redis '.($serverInfo['redis_version'] ?? 'unknown')], $serverInfo), JSON_PRETTY_PRINT),
            ],
        ];
    }

    /**
     * Build the base redis-cli command parts with host, port, and auth.
     *
     * @return array<string>
     */
    private function buildBaseCommand(): array
    {
        $parts = ['redis-cli'];
        $parts[] = '-h '.escapeshellarg($this->config['host']);
        $parts[] = '-p '.escapeshellarg((string) $this->config['port']);
        $parts = array_merge($parts, $this->buildAuthFlags());
        $parts[] = '--no-auth-warning';

        return $parts;
    }

    private function buildPingCommand(): string
    {
        return implode(' ', [...$this->buildBaseCommand(), 'PING']);
    }

    private function buildInfoCommand(): string
    {
        return implode(' ', [...$this->buildBaseCommand(), 'INFO server']);
    }

    /**
     * Build authentication flags for redis-cli.
     *
     * @return array<string>
     */
    private function buildAuthFlags(): array
    {
        $flags = [];
        $pass = $this->config['pass'] ?? '';
        $user = $this->config['user'] ?? '';

        if (! empty($pass)) {
            if (! empty($user)) {
                $flags[] = '--user '.escapeshellarg($user);
            }
            $flags[] = '-a '.escapeshellarg($pass);
        }

        return $flags;
    }
}
