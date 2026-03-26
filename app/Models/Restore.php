<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $snapshot_id
 * @property string $target_server_id
 * @property string $schema_name
 * @property array<string, mixed>|null $options
 * @property string|null $triggered_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $backup_job_id
 * @property-read BackupJob $job
 * @property-read Snapshot $snapshot
 * @property-read DatabaseServer $targetServer
 * @property-read User|null $triggeredBy
 *
 * @method static Builder<static>|Restore newModelQuery()
 * @method static Builder<static>|Restore newQuery()
 * @method static Builder<static>|Restore query()
 * @method static Builder<static>|Restore whereBackupJobId($value)
 * @method static Builder<static>|Restore whereCreatedAt($value)
 * @method static Builder<static>|Restore whereId($value)
 * @method static Builder<static>|Restore whereSchemaName($value)
 * @method static Builder<static>|Restore whereSnapshotId($value)
 * @method static Builder<static>|Restore whereTargetServerId($value)
 * @method static Builder<static>|Restore whereTriggeredByUserId($value)
 * @method static Builder<static>|Restore whereUpdatedAt($value)
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
        'options',
        'triggered_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    /**
     * Get a restore option value.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return is_array($this->options) ? ($this->options[$key] ?? $default) : $default;
    }

    /**
     * @return BelongsTo<Snapshot, Restore>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    /**
     * @return BelongsTo<DatabaseServer, Restore>
     */
    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class, 'target_server_id');
    }

    /**
     * @return BelongsTo<User, Restore>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /**
     * @return BelongsTo<BackupJob, Restore>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }
}
