<?php

namespace App\Models;

use Database\Factories\BackupScheduleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperBackupSchedule
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
