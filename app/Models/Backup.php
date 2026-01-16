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

    protected $fillable = [
        'database_server_id',
        'volume_id',
        'path',
        'recurrence',
        'retention_days',
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
