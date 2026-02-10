<?php

namespace App\Models;

use Database\Factories\BackupScheduleFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $expression
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Backup> $backups
 * @property-read int|null $backups_count
 *
 * @mixin \Eloquent
 */
class BackupSchedule extends Model
{
    /** @use HasFactory<BackupScheduleFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'expression',
    ];

    /**
     * @return HasMany<Backup, BackupSchedule>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
