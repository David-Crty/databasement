<?php

namespace App\Contracts;

interface BackupLogger
{
    /**
     * Add a command log entry with full details.
     */
    public function logCommand(string $command, ?string $output = null, ?int $exitCode = null, ?float $startTime = null): void;

    /**
     * Start a command log entry (before execution begins).
     * Returns the index of the created log entry for later updates.
     */
    public function startCommandLog(string $command): int;

    /**
     * Update an existing command log entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCommandLog(int $index, array $data): void;

    /**
     * Add a log entry.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function log(string $message, string $level = 'info', ?array $context = null): void;

    /**
     * Get all logs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogs(): array;
}
