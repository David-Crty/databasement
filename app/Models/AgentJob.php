<?php

namespace App\Models;

use Database\Factories\AgentJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperAgentJob
 */
class AgentJob extends Model
{
    /** @use HasFactory<AgentJobFactory> */
    use HasFactory, HasUlids;

    public const TYPE_BACKUP = 'backup';

    public const TYPE_DISCOVER = 'discover';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'database_server_id',
        'agent_id',
        'snapshot_id',
        'status',
        'payload',
        'lease_expires_at',
        'attempts',
        'max_attempts',
        'claimed_at',
        'completed_at',
        'error_message',
        'logs',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'logs' => 'array',
            'lease_expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Agent, AgentJob>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<DatabaseServer, AgentJob>
     */
    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    /**
     * @return BelongsTo<Snapshot, AgentJob>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    /**
     * Claim this job for an agent.
     */
    public function claim(Agent $agent, int $leaseDurationSeconds = 300): void
    {
        $this->update([
            'agent_id' => $agent->id,
            'status' => self::STATUS_CLAIMED,
            'lease_expires_at' => now()->addSeconds($leaseDurationSeconds),
            'claimed_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Mark this job as running.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
        ]);
    }

    /**
     * Mark this job as completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Mark this job as failed.
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Extend the lease on this job.
     */
    public function extendLease(int $leaseDurationSeconds = 300): void
    {
        $this->update([
            'lease_expires_at' => now()->addSeconds($leaseDurationSeconds),
        ]);
    }

    /**
     * Check if the lease has expired.
     */
    public function isLeaseExpired(): bool
    {
        return $this->lease_expires_at !== null
            && $this->lease_expires_at->isPast();
    }
}
