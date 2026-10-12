<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Enums\DatabaseType;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\Databases\Concerns\TestsConnection;
use App\Services\Backup\DTO\DatabaseOperationResult;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class RedisDatabase implements DatabaseInterface
{
    use TestsConnection;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function listDatabases(): array
    {
        return ['all'];
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $parts = $this->buildBaseCommand();

        if (! empty($this->config['dump_flags'])) {
            $parts[] = DatabaseOperationResult::escapeFlags($this->config['dump_flags'], DatabaseType::REDIS);
        }

        $parts[] = '--rdb -';

        return new DatabaseOperationResult(command: implode(' ', $parts), writesToStdout: true);
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        throw new UnsupportedDatabaseTypeException('redis');
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        throw new UnsupportedDatabaseTypeException('redis');
    }

    public function testConnection(): array
    {
        return $this->probeConnection($this->buildPingCommand(), function (ProcessResult $pingResult, int $durationMs): array {
            if (! str_contains($pingResult->output(), 'PONG')) {
                return $this->connectionFailed('Unexpected response from Redis server: '.trim($pingResult->output()));
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

            return $this->connectionSucceededWithInfo($durationMs, array_merge(['dbms' => 'Redis '.($serverInfo['redis_version'] ?? 'unknown')], $serverInfo));
        });
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
            $flags[] = '--pass '.escapeshellarg($pass);
        }

        return $flags;
    }
}
