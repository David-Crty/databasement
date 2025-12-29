<?php

namespace App\Models;

use Database\Factories\VolumeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $type
 * @property array<array-key, mixed> $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Backup> $backups
 * @property-read int|null $backups_count
 * @property-read Collection<int, Snapshot> $snapshots
 * @property-read int|null $snapshots_count
 *
 * @method static VolumeFactory factory($count = null, $state = [])
 * @method static Builder<static>|Volume newModelQuery()
 * @method static Builder<static>|Volume newQuery()
 * @method static Builder<static>|Volume query()
 * @method static Builder<static>|Volume whereConfig($value)
 * @method static Builder<static>|Volume whereCreatedAt($value)
 * @method static Builder<static>|Volume whereId($value)
 * @method static Builder<static>|Volume whereName($value)
 * @method static Builder<static>|Volume whereType($value)
 * @method static Builder<static>|Volume whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Volume extends Model
{
    /** @use HasFactory<VolumeFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'name',
        'type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<Backup, Volume>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * @return HasMany<Snapshot, Volume>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    /**
     * Check if volume has any snapshots (making it immutable).
     */
    public function hasSnapshots(): bool
    {
        return $this->snapshots()->exists();
    }
}
