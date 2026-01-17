<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $database_server_id
 * @property string $volume_id
 * @property string|null $path
 * @property string $recurrence
 * @property int|null $retention_days
 * @property string $retention_policy
 * @property int|null $gfs_keep_daily
 * @property int|null $gfs_keep_weekly
 * @property int|null $gfs_keep_monthly
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseServer $databaseServer
 * @property-read Collection<int, Snapshot> $snapshots
 * @property-read int|null $snapshots_count
 * @property-read Volume $volume
 *
 * @method static Builder<static>|Backup newModelQuery()
 * @method static Builder<static>|Backup newQuery()
 * @method static Builder<static>|Backup query()
 * @method static Builder<static>|Backup whereCreatedAt($value)
 * @method static Builder<static>|Backup whereDatabaseServerId($value)
 * @method static Builder<static>|Backup whereId($value)
 * @method static Builder<static>|Backup wherePath($value)
 * @method static Builder<static>|Backup whereRecurrence($value)
 * @method static Builder<static>|Backup whereRetentionDays($value)
 * @method static Builder<static>|Backup whereRetentionPolicy($value)
 * @method static Builder<static>|Backup whereGfsKeepDaily($value)
 * @method static Builder<static>|Backup whereGfsKeepWeekly($value)
 * @method static Builder<static>|Backup whereGfsKeepMonthly($value)
 * @method static Builder<static>|Backup whereUpdatedAt($value)
 * @method static Builder<static>|Backup whereVolumeId($value)
 *
 * @mixin \Eloquent
 */
class Backup extends Model
{
    use HasUlids;

    public const string RECURRENCE_DAILY = 'daily';

    public const string RECURRENCE_WEEKLY = 'weekly';

    public const array RECURRENCE_TYPES = [
        self::RECURRENCE_DAILY,
        self::RECURRENCE_WEEKLY,
    ];

    public const string RETENTION_DAYS = 'days';

    public const string RETENTION_GFS = 'gfs';

    public const string RETENTION_FOREVER = 'forever';

    public const array RETENTION_POLICIES = [
        self::RETENTION_DAYS,
        self::RETENTION_GFS,
        self::RETENTION_FOREVER,
    ];

    protected $fillable = [
        'database_server_id',
        'volume_id',
        'path',
        'recurrence',
        'retention_days',
        'retention_policy',
        'gfs_keep_daily',
        'gfs_keep_weekly',
        'gfs_keep_monthly',
    ];

    /**
     * @return BelongsTo<DatabaseServer, Backup>
     */
    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    /**
     * @return BelongsTo<Volume, Backup>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return HasMany<Snapshot, Backup>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }
}
