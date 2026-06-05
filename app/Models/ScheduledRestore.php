<?php

namespace App\Models;

use Database\Factories\ScheduledRestoreFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @mixin IdeHelperScheduledRestore
 */
class ScheduledRestore extends Model
{
    /** @use HasFactory<ScheduledRestoreFactory> */
    use HasFactory, HasUlids;

    public const string SKIP_NO_SNAPSHOT = 'no_snapshot';

    public const string SKIP_PREVIOUS_IN_FLIGHT = 'previous_in_flight';

    public const string SKIP_DISABLED = 'disabled';

    protected $fillable = [
        'name',
        'source_server_id',
        'source_database_name',
        'target_server_id',
        'schema_name',
        'backup_schedule_id',
        'options',
        'enabled',
        'last_executed_at',
        'last_skip_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'enabled' => 'boolean',
            'last_executed_at' => 'datetime',
        ];
    }

    /**
     * Get a scheduled restore option value.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return is_array($this->options) ? ($this->options[$key] ?? $default) : $default;
    }

    /**
     * @return BelongsTo<DatabaseServer, ScheduledRestore>
     */
    public function sourceServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class, 'source_server_id');
    }

    /**
     * @return BelongsTo<DatabaseServer, ScheduledRestore>
     */
    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class, 'target_server_id');
    }

    /**
     * @return HasOne<Restore, ScheduledRestore>
     */
    public function lastRestore(): HasOne
    {
        return $this->hasOne(Restore::class)->latestOfMany();
    }

    /**
     * @return BelongsTo<BackupSchedule, ScheduledRestore>
     */
    public function backupSchedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class);
    }

    /**
     * @return HasMany<Restore, ScheduledRestore>
     */
    public function restores(): HasMany
    {
        return $this->hasMany(Restore::class);
    }
}
