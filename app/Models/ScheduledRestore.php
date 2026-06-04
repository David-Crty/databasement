<?php

namespace App\Models;

use Database\Factories\ScheduledRestoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $source_server_id
 * @property string|null $source_database_name
 * @property string $target_server_id
 * @property string $schema_name
 * @property string $backup_schedule_id
 * @property array<string, mixed>|null $options
 * @property bool $enabled
 * @property Carbon|null $last_executed_at
 * @property string|null $last_restore_id
 * @property string|null $last_skip_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseServer $sourceServer
 * @property-read DatabaseServer $targetServer
 * @property-read Restore|null $lastRestore
 * @property-read BackupSchedule $backupSchedule
 * @property-read Collection<int, Restore> $restores
 * @method static ScheduledRestoreFactory factory($count = null, $state = [])
 * @method static Builder<static>|ScheduledRestore newModelQuery()
 * @method static Builder<static>|ScheduledRestore newQuery()
 * @method static Builder<static>|ScheduledRestore query()
 * @mixin \Eloquent
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
        'last_restore_id',
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
     * @return BelongsTo<Restore, ScheduledRestore>
     */
    public function lastRestore(): BelongsTo
    {
        return $this->belongsTo(Restore::class, 'last_restore_id');
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
