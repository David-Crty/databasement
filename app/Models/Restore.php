<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $snapshot_id
 * @property string $target_server_id
 * @property string $schema_name
 * @property string|null $triggered_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $backup_job_id
 * @property-read \App\Models\BackupJob $job
 * @property-read \App\Models\Snapshot $snapshot
 * @property-read \App\Models\DatabaseServer $targetServer
 * @property-read \App\Models\User|null $triggeredBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereBackupJobId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereSchemaName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereSnapshotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereTargetServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereTriggeredByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Restore whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Restore extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        // Delete the associated job when restore is deleted
        static::deleting(function (Restore $restore) {
            $restore->job->delete();
        });
    }

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
}
