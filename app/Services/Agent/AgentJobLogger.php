<?php

namespace App\Services\Agent;

use App\Exceptions\Backup\JobRevokedException;
use App\Services\Backup\InMemoryBackupLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The agent's counterpart of a {@see \App\Models\BackupJob} as a logger: each
 * write is sent to the server right away, as a job heartbeat carrying the
 * entries added or changed since the last successful send.
 *
 * A send that fails is retried with the next write and never stops the job,
 * except a {@see JobRevokedException}: the job is no longer this agent's.
 */
class AgentJobLogger extends InMemoryBackupLogger
{
    /** @var array<int, true> Indexes of the entries not sent since they last changed */
    private array $unsent = [];

    /**
     * @param  bool  $serverMergesLogs  Whether the server replaces a running command sent again;
     *                                  one that does not only receives commands once they are final.
     */
    public function __construct(
        private readonly AgentApiClient $client,
        private readonly string $jobId,
        private readonly bool $serverMergesLogs,
    ) {}

    public function logCommand(string $command, ?string $output = null, ?int $exitCode = null, ?float $startTime = null): void
    {
        parent::logCommand($command, $output, $exitCode, $startTime);
        $this->changed(array_key_last($this->getLogs()));
    }

    public function startCommandLog(string $command): int
    {
        $index = parent::startCommandLog($command);
        $this->changed($index);

        return $index;
    }

    public function updateCommandLog(int $index, array $data): void
    {
        parent::updateCommandLog($index, $data);

        if (isset($this->getLogs()[$index])) {
            $this->changed($index);
        }
    }

    public function log(string $message, string $level = 'info', ?array $context = null): void
    {
        parent::log($message, $level, $context);
        $this->changed(array_key_last($this->getLogs()));
    }

    /**
     * The entries the server has not received yet, for the job's final report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unsentLogs(): array
    {
        return array_values(array_intersect_key($this->getLogs(), $this->unsent));
    }

    private function changed(int $index): void
    {
        $this->unsent[$index] = true;

        $logs = $this->getLogs();
        $sendable = array_filter(
            $this->unsent,
            fn (bool $unsent, int $i) => $this->serverMergesLogs || ($logs[$i]['status'] ?? null) !== 'running',
            ARRAY_FILTER_USE_BOTH,
        );

        try {
            $this->client->jobHeartbeat($this->jobId, array_values(array_intersect_key($logs, $sendable)));
        } catch (JobRevokedException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning("Could not report job {$this->jobId}: {$e->getMessage()}");

            return;
        }

        $this->unsent = array_diff_key($this->unsent, $sendable);
    }
}
