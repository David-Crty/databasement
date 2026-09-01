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
            'duration_ms' => $this->calculateDuration(),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'logs' => $this->logsWithRunningCommandsClosed('failed'),
        ]);
    }

    /**
     * Close out any command log entries still marked "running", so a job that fails
     * before its last command finishes (e.g. an exception escaping mid-execution, or
     * a timed-out job recovered by RecoverStuckJobsCommand) doesn't leave the logs
     * modal showing a permanently spinning command.
     *
     * @return array<int, array<string, mixed>>
     */
    private function logsWithRunningCommandsClosed(string $status): array
    {
        return array_map(function (array $log) use ($status) {
            if (($log['type'] ?? null) === 'command' && ($log['status'] ?? null) === 'running') {
                $log['status'] = $status;
            }

            return $log;
        }, $this->logs ?? []);
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
     * Mark job as running
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => BackupJobStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * Add a command log entry
     */
    public function logCommand(string $command, ?string $output = null, ?int $exitCode = null, ?float $startTime = null): void
    {
        $logs = $this->logs ?? [];

        $logs[] = [
            'timestamp' => now()->toIso8601String(),
            'type' => 'command',
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_ms' => $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null,
        ];

        $this->update(['logs' => $logs]);
    }

    /**
     * Start a command log entry (before execution begins)
     * Returns the index of the created log entry for later updates
     */
    public function startCommandLog(string $command): int
    {
        $logs = $this->logs ?? [];

        $logs[] = [
            'timestamp' => now()->toIso8601String(),
            'type' => 'command',
            'command' => $command,
            'status' => 'running',
            'output' => null,
            'exit_code' => null,
            'duration_ms' => null,
        ];

        $this->update(['logs' => $logs]);

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

        $this->update(['logs' => $logs]);
    }

    /**
     * Add a log entry
     *
     * @param  array<string, mixed>|null  $context
     */
    public function log(string $message, string $level = 'info', ?array $context = null): void
    {
        $logs = $this->logs ?? [];

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'type' => 'log',
            'level' => $level,
            'message' => $message,
        ];

        if ($context !== null) {
            $entry['context'] = $context;
        }

        $logs[] = $entry;

        $this->update(['logs' => $logs]);
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
