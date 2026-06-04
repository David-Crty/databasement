<?php

namespace App\Models;

use App\Contracts\BackupLogger;
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
            'status' => 'completed',
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
            'status' => 'failed',
            'completed_at' => now(),
            'duration_ms' => $this->calculateDuration(),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
        ]);
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
            'status' => 'running',
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
