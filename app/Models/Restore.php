<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperRestore
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
