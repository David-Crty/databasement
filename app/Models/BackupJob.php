<?php

namespace App\Models;

use App\Contracts\BackupLogger;
use App\Enums\BackupJobStatus;
use App\Models\Scopes\OrganizationScope;
use App\Services\CurrentOrganization;
use App\Support\Formatters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @mixin IdeHelperBackupJob
 */
class BackupJob extends Model implements BackupLogger
{
    use HasUlids;

    /**
     * Seconds without a command heartbeat after which a running job is shown
     * as possibly stalled. Heartbeats are sent every 30 seconds.
     */
    public const int COMMAND_HEARTBEAT_WARNING_SECONDS = 120;

    /**
     * Columns a listing needs, excluding the potentially huge `logs` and
     * `error_trace` payloads.
     *
     * @var array<int, string>
     */
    private const SUMMARY_COLUMNS = [
        'id',
        'job_id',
        'status',
        'started_at',
        'completed_at',
        'command_heartbeat_at',
        'duration_ms',
        'error_message',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'job_id',
        'status',
        'started_at',
        'completed_at',
        'command_heartbeat_at',
        'duration_ms',
        'error_message',
        'error_trace',
        'logs',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupJobStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'command_heartbeat_at' => 'datetime',
            'duration_ms' => 'integer',
            'logs' => 'array',
        ];
    }

    /**
     * Scope to filter backup jobs by the current organization.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentOrg(Builder $query): Builder
    {
        $orgId = app(CurrentOrganization::class)->id();

        return $query->where(function (Builder $q) use ($orgId) {
            $q->whereHas('snapshot.databaseServer', function (Builder $sq) use ($orgId) {
                $sq->withoutGlobalScope(OrganizationScope::class)
                    ->whereRaw('organization_id = ?', [$orgId]);
            })
                ->orWhereHas('restore.targetServer', function (Builder $sq) use ($orgId) {
                    $sq->withoutGlobalScope(OrganizationScope::class)
                        ->whereRaw('organization_id = ?', [$orgId]);
                });
        });
    }

    /**
     * Scope for listings: selects everything needed to render a job row except the
     * `logs` JSON blob, which can reach megabytes on a chatty command and would
     * otherwise be fetched and decoded for every row on the page.
     *
     * Jobs loaded through this scope must not be used to read logs — the logs
     * modal re-fetches the job in full.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutLogs(Builder $query): Builder
    {
        return $query->select(self::SUMMARY_COLUMNS);
    }

    /**
     * Scope to jobs that are still in progress (pending or running).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('status', [BackupJobStatus::Pending, BackupJobStatus::Running]);
    }

    /**
     * @return HasOne<Snapshot, BackupJob>
     */
    public function snapshot(): HasOne
    {
        return $this->hasOne(Snapshot::class);
    }

    /**
     * @return HasOne<Restore, BackupJob>
     */
    public function restore(): HasOne
    {
        return $this->hasOne(Restore::class);
    }

    /**
     * Get human-readable duration
     */
    public function getHumanDuration(): ?string
    {
        return Formatters::humanDuration($this->duration_ms);
    }

    /**
     * Mark job as completed
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => BackupJobStatus::Completed,
            'completed_at' => now(),
            'command_heartbeat_at' => null,
            'duration_ms' => $this->calculateDuration(),
        ]);
    }

    /**
     * Mark job as failed
     */
    public function markFailed(\Throwable $exception): void
    {
        $this->update([
            'status' => BackupJobStatus::Failed,
            'completed_at' => now(),
            'command_heartbeat_at' => null,
            'duration_ms' => $this->calculateDuration(),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'logs' => $this->logsWithDanglingCommandsFailed(),
        ]);
    }

    /**
     * Mark job as running, on its first attempt or a retry
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => BackupJobStatus::Running,
            'started_at' => now(),
            'command_heartbeat_at' => null,
            'logs' => $this->logsWithDanglingCommandsFailed(),
        ]);
    }

    /**
     * Record that the agent running this job was lost before it finished.
     */
    public function markAgentLost(): void
    {
        $this->saveLogs([
            ...$this->logsWithDanglingCommandsFailed(),
            self::logEntry('Lost contact with the agent, the job will be retried.', 'warning'),
        ]);
    }

    /**
     * A command log entry is left at `status: 'running'` when its runner dies
     * mid-command (queue timeout, worker or agent killed). Once the job ends,
     * is retried or loses its agent, that command will never finish: marking
     * it failed keeps the log modal from showing it running forever, and
     * keeps it from counting as a running command in {@see self::saveLogs()}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function logsWithDanglingCommandsFailed(): array
    {
        $logs = $this->logs ?? [];

        foreach ($logs as $index => $entry) {
            if (($entry['status'] ?? null) === 'running') {
                $logs[$index]['status'] = 'failed';
            }
        }

        return $logs;
    }

    /**
     * Calculate duration from started_at to now.
     */
    private function calculateDuration(): ?int
    {
        return $this->started_at
            ? (int) $this->started_at->diffInMilliseconds(now())
            : null;
    }

    /**
     * Add a command log entry
     */
    public function logCommand(string $command, ?string $output = null, ?int $exitCode = null, ?float $startTime = null): void
    {
        $this->saveLogs([...($this->logs ?? []), [
            'timestamp' => now()->toIso8601String(),
            'type' => 'command',
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_ms' => $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null,
        ]]);
    }

    /**
     * Start a command log entry (before execution begins)
     * Returns the index of the created log entry for later updates
     */
    public function startCommandLog(string $command): int
    {
        $logs = [...($this->logs ?? []), [
            'timestamp' => now()->toIso8601String(),
            'type' => 'command',
            'command' => $command,
            'status' => 'running',
            'output' => null,
            'exit_code' => null,
            'duration_ms' => null,
        ]];

        $this->saveLogs($logs);

        return count($logs) - 1;
    }

    /**
     * Update an existing command log entry
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCommandLog(int $index, array $data): void
    {
        $logs = $this->logs ?? [];

        if (! isset($logs[$index])) {
            return;
        }

        $logs[$index] = array_merge($logs[$index], $data);

        $this->saveLogs($logs);
    }

    /**
     * Add a log entry
     *
     * @param  array<string, mixed>|null  $context
     */
    public function log(string $message, string $level = 'info', ?array $context = null): void
    {
        $this->saveLogs([...($this->logs ?? []), self::logEntry($message, $level, $context)]);
    }

    /**
     * Merge log entries recorded by a remote agent. A runner runs one command
     * at a time and resends it while it runs, with more output and finally
     * its result: an incoming command entry replaces the job's running
     * command, if there is one. Everything else is appended.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function mergeLogs(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $logs = $this->logs ?? [];

        foreach ($entries as $entry) {
            $running = ($entry['type'] ?? null) === 'command' ? self::runningCommandIndex($logs) : null;

            if ($running === null) {
                $logs[] = $entry;
            } else {
                $logs[$running] = $entry;
            }
        }

        $this->saveLogs($logs);
    }

    /**
     * Every log write goes through here. While a command is running, each
     * write refreshes `command_heartbeat_at`: runners report a running
     * command every 30 seconds, so a stale value means its runner was lost.
     *
     * @param  array<int, array<string, mixed>>  $logs
     */
    private function saveLogs(array $logs): void
    {
        $this->update([
            'logs' => $logs,
            'command_heartbeat_at' => self::runningCommandIndex($logs) === null ? null : now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     */
    private static function runningCommandIndex(array $logs): ?int
    {
        foreach (array_reverse($logs, preserve_keys: true) as $index => $entry) {
            if (($entry['status'] ?? null) === 'running') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private static function logEntry(string $message, string $level, ?array $context = null): array
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

        return $entry;
    }

    /**
     * Get all logs
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogs(): array
    {
        return $this->logs ?? [];
    }
}
