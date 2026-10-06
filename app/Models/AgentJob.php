<?php

namespace App\Models;

use App\Enums\AgentJobType;
use App\Services\Agent\Handlers\AgentJobHandler;
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
        'restore_id',
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
            'type' => AgentJobType::class,
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
     * @return BelongsTo<Restore, AgentJob>
     */
    public function restore(): BelongsTo
    {
        return $this->belongsTo(Restore::class);
    }

    /**
     * Queue a job for the agent of the given server.
     *
     * @param  array<string, mixed>  $payload  Self-contained work order the agent runs from
     * @param  array{snapshot_id?: string, restore_id?: string}  $attributes  Links to the records the job reports to
     */
    public static function enqueue(AgentJobType $type, string $databaseServerId, array $payload, array $attributes = []): self
    {
        return self::create([
            ...$attributes,
            'type' => $type,
            'database_server_id' => $databaseServerId,
            'status' => self::STATUS_PENDING,
            'payload' => $payload,
            'max_attempts' => $type->handler()->maxAttempts(),
        ]);
    }

    public function handler(): AgentJobHandler
    {
        return $this->type->handler();
    }

    /**
     * The job record the UI shows for this agent job; null for job types
     * that have none, such as discovery.
     */
    public function trackedJob(): ?BackupJob
    {
        return $this->handler()->trackedJob($this);
    }

    /**
     * Claim this job for an agent.
     */
    public function claim(Agent $agent): void
    {
        $this->update([
            'agent_id' => $agent->id,
            'status' => self::STATUS_CLAIMED,
            'lease_expires_at' => now()->addSeconds($this->handler()->leaseSeconds()),
            'claimed_at' => now(),
            'attempts' => $this->attempts + 1,
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
    public function extendLease(): void
    {
        $this->update([
            'lease_expires_at' => now()->addSeconds($this->handler()->leaseSeconds()),
        ]);
    }
}
