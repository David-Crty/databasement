<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $backup_job_id
 * @property string $snapshot_id
 * @property string $target_server_id
 * @property string $schema_name
 * @property string|null $triggered_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Snapshot $snapshot
 * @property-read \App\Models\DatabaseServer $targetServer
 * @property-read \App\Models\User|null $triggeredBy
 * @property-read \App\Models\BackupJob $job
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore query()
 *
 * @mixin \Eloquent
 */
class Restore extends Model
{
    use HasUlids;

    protected $fillable = [
        'backup_job_id',
        'snapshot_id',
        'target_server_id',
        'schema_name',
        'triggered_by_user_id',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class, 'target_server_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    /**
     * Scope to filter by queued status
     */
    public function scopeQueued($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'queued'));
    }

    /**
     * Scope to filter by completed status
     */
    public function scopeCompleted($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'completed'));
    }

    /**
     * Scope to filter by failed status
     */
    public function scopeFailed($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'failed'));
    }

    /**
     * Scope to filter by running status
     */
    public function scopeRunning($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'running'));
    }

    /**
     * Scope to filter by pending status
     */
    public function scopePending($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'pending'));
    }
}
