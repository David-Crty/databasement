<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string|null $job_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $error_message
 * @property string|null $error_trace
 * @property array<array-key, mixed>|null $logs
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Restore|null $restore
 * @property-read \App\Models\Snapshot|null $snapshot
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereErrorTrace($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereJobId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereLogs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BackupJob whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class BackupJob extends Model
{
    use HasUlids;

    protected $fillable = [
        'job_id',
        'status',
        'started_at',
        'completed_at',
        'error_message',
        'error_trace',
        'logs',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'logs' => 'array',
        ];
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(Snapshot::class);
    }

    public function restore(): HasOne
    {
        return $this->hasOne(Restore::class);
    }

    /**
     * Calculate the duration of the job in milliseconds
     */
    public function getDurationMs(): ?int
    {
        if ($this->completed_at === null || $this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->completed_at);
    }

    /**
     * Get human-readable duration
     */
    public function getHumanDuration(): ?string
    {
        $ms = $this->getDurationMs();

        if ($ms === null) {
            return null;
        }

        if ($ms < 1000) {
            return "{$ms}ms";
        }

        $seconds = round($ms / 1000, 2);

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds % 60, 2);

        return "{$minutes}m {$remainingSeconds}s";
    }

    /**
     * Mark job as completed
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
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
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
        ]);
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
     * Add a log entry
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
     */
    public function getLogs(): array
    {
        return $this->logs ?? [];
    }

    /**
     * Get logs filtered by type
     */
    public function getLogsByType(string $type): array
    {
        return array_filter($this->getLogs(), fn ($log) => ($log['type'] ?? null) === $type);
    }

    /**
     * Get command logs only
     */
    public function getCommandLogs(): array
    {
        return $this->getLogsByType('command');
    }
}
