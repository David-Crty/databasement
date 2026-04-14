<?php

namespace App\Models;

use App\Enums\DatabaseSelectionMode;
use Database\Factories\BackupFactory;
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
 * @property string $database_server_id
 * @property string $volume_id
 * @property string|null $path
 * @property string $backup_schedule_id
 * @property int|null $retention_days
 * @property string $retention_policy
 * @property int|null $gfs_keep_daily
 * @property int|null $gfs_keep_weekly
 * @property int|null $gfs_keep_monthly
 * @property DatabaseSelectionMode $database_selection_mode
 * @property array<string>|null $database_names
 * @property string|null $database_include_pattern
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseServer $databaseServer
 * @property-read BackupSchedule $backupSchedule
 * @property-read Collection<int, Snapshot> $snapshots
 * @property-read int|null $snapshots_count
 * @property-read Volume $volume
 *
 * @method static BackupFactory factory($count = null, $state = [])
 * @method static Builder<static>|Backup newModelQuery()
 * @method static Builder<static>|Backup newQuery()
 * @method static Builder<static>|Backup query()
 *
 * @mixin \Eloquent
 */
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory;

    use HasUlids;

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
        'backup_schedule_id',
        'retention_days',
        'retention_policy',
        'gfs_keep_daily',
        'gfs_keep_weekly',
        'gfs_keep_monthly',
        'database_selection_mode',
        'database_names',
        'database_include_pattern',
    ];

    protected function casts(): array
    {
        return [
            'database_selection_mode' => DatabaseSelectionMode::class,
            'database_names' => 'array',
            'retention_days' => 'integer',
            'gfs_keep_daily' => 'integer',
            'gfs_keep_weekly' => 'integer',
            'gfs_keep_monthly' => 'integer',
        ];
    }

    /**
     * One-line summary of the backup config for the index table, logs, and tooltips.
     *
     * @param  bool  $toString  When false, returns an associative array of parts.
     * @return ($toString is false ? array{schedule: string, volume: string, databases: string, retention: string} : string)
     */
    public function getDisplayLabel(bool $toString = true): string|array
    {
        $parts = [
            'schedule' => $this->backupSchedule->name,
            'volume' => $this->volume->name,
            'databases' => $this->getDatabaseSummary(),
            'retention' => $this->getRetentionSummary(),
        ];

        if (! $toString) {
            return $parts;
        }

        return implode(' · ', array_filter([
            $parts['schedule'].' → '.$parts['volume'],
            $parts['databases'],
            $parts['retention'],
        ]));
    }

    /**
     * Short description of which databases this config backs up.
     */
    public function getDatabaseSummary(): string
    {
        $isSqlite = $this->databaseServer->database_type->value === 'sqlite';

        if ($isSqlite) {
            $paths = $this->database_names ?? [];
            if ($paths === []) {
                return '';
            }
            $basenames = array_map('basename', $paths);

            return count($basenames) <= 2
                ? implode(', ', $basenames)
                : $basenames[0].', +'.(count($basenames) - 1);
        }

        return match ($this->database_selection_mode) {
            DatabaseSelectionMode::All => __('All databases'),
            DatabaseSelectionMode::Selected => $this->formatSelectedDatabases(),
            DatabaseSelectionMode::Pattern => '/'.$this->database_include_pattern.'/',
        };
    }

    private function formatSelectedDatabases(): string
    {
        $names = $this->database_names ?? [];
        if ($names === []) {
            return '';
        }

        return count($names) <= 2
            ? implode(', ', $names)
            : $names[0].', +'.(count($names) - 1);
    }

    /**
     * Short retention label: "30d", "GFS 7d/4w/3m", or "Forever".
     */
    public function getRetentionSummary(): string
    {
        return match ($this->retention_policy) {
            self::RETENTION_GFS => 'GFS '.($this->gfs_keep_daily ?? 0).'d/'.($this->gfs_keep_weekly ?? 0).'w/'.($this->gfs_keep_monthly ?? 0).'m',
            self::RETENTION_FOREVER => __('Forever'),
            default => $this->retention_days ? $this->retention_days.'d' : '',
        };
    }

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
     * @return BelongsTo<BackupSchedule, Backup>
     */
    public function backupSchedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class);
    }

    /**
     * @return HasMany<Snapshot, Backup>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }
}
