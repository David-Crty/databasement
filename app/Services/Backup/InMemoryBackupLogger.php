<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;

class InMemoryBackupLogger implements BackupLogger
{
    /** @var array<int, array<string, mixed>> */
    private array $logs = [];

    private int $flushedIndex = 0;

    public function logCommand(string $command, ?string $output = null, ?int $exitCode = null, ?float $startTime = null): void
    {
        $this->logs[] = [
            'timestamp' => now()->toIso8601String(),
            'type' => 'command',
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_ms' => $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null,
        ];
    }

    public function startCommandLog(string $command): int
    {
        $this->logs[] = [
            'timestamp' => now()->toIso8601String(),
            'type' => 'command',
            'command' => $command,
            'status' => 'running',
            'output' => null,
            'exit_code' => null,
            'duration_ms' => null,
        ];

        return count($this->logs) - 1;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCommandLog(int $index, array $data): void
    {
        if (! isset($this->logs[$index])) {
            return;
        }

        $this->logs[$index] = array_merge($this->logs[$index], $data);
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function log(string $message, string $level = 'info', ?array $context = null): void
    {
        $entry = [
            'timestamp' => now()->toIso8601String(),
            'type' => 'log',
            'level' => $level,
            'message' => $message,
        ];

        if ($context !== null) {
            $entry['context'] = $context;
        }

        $this->logs[] = $entry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function flush(): array
    {
        $new = array_slice($this->logs, $this->flushedIndex);
        $this->flushedIndex = count($this->logs);

        return $new;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
}
