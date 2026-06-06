<?php

namespace App\Models;

use App\Support\Formatters;
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
     * Human label combining the schedule name, the raw cron expression, and a
     * natural-language translation of it. Used wherever a schedule is shown
     * inline (select options, summaries) so the formatting stays consistent.
     */
    public function displayLabel(): string
    {
        return $this->name.' — '.$this->expression.' ('.$this->cronTranslation().')';
    }

    /**
     * Natural-language translation of the cron expression (e.g. "At 02:00").
     */
    public function cronTranslation(): string
    {
        return Formatters::cronTranslation($this->expression);
    }

    /**
     * @return HasMany<Backup, BackupSchedule>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * @return HasMany<ScheduledRestore, BackupSchedule>
     */
    public function scheduledRestores(): HasMany
    {
        return $this->hasMany(ScheduledRestore::class);
    }
}
