<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $database_server_id
 * @property string $backup_id
 * @property string $volume_id
 * @property string $path
 * @property int $file_size
 * @property string|null $checksum
 * @property \Illuminate\Support\Carbon $started_at
 * @property string $database_name
 * @property string $database_type
 * @property string $database_host
 * @property int $database_port
 * @property string $compression_type
 * @property string $method
 * @property string|null $triggered_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $backup_job_id
 * @property-read \App\Models\Backup $backup
 * @property-read \App\Models\DatabaseServer $databaseServer
 * @property-read \App\Models\BackupJob $job
 * @property-read \App\Models\User|null $triggeredBy
 * @property-read \App\Models\Volume $volume
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot completed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot failed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot forDatabaseServer(\App\Models\DatabaseServer $databaseServer)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot running()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereBackupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereBackupJobId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereChecksum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereCompressionType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereDatabaseHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereDatabaseName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereDatabasePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereDatabaseServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereDatabaseType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereFileSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereTriggeredByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Snapshot whereVolumeId($value)
 *
 * @mixin \Eloquent
 */
class Snapshot extends Model
{
    use HasUlids;

    protected $fillable = [
        'backup_job_id',
        'database_server_id',
        'backup_id',
        'volume_id',
        'path',
        'file_size',
        'checksum',
        'started_at',
        'database_name',
        'database_type',
        'database_host',
        'database_port',
        'compression_type',
        'method',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'file_size' => 'integer',
            'database_port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Delete the backup file when snapshot is deleted
        static::deleting(function (Snapshot $snapshot) {
            $snapshot->deleteBackupFile();
        });
    }

    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    /**
     * Get human-readable file size
     */
    public function getHumanFileSize(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Delete the backup file from the volume
     */
    public function deleteBackupFile(): bool
    {
        try {
            // Get the filesystem for this volume
            $filesystemProvider = app(\App\Services\Backup\Filesystems\FilesystemProvider::class);
            $filesystem = $filesystemProvider->getForVolume($this->volume);

            // Delete the file if it exists
            if ($filesystem->fileExists($this->path)) {
                $filesystem->delete($this->path);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            // Log the error but don't throw to prevent deletion cascade failure
            logger()->error('Failed to delete backup file for snapshot', [
                'snapshot_id' => $this->id,
                'path' => $this->path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark snapshot as completed with optional checksum
     */
    public function markCompleted(?string $checksum = null): void
    {
        $this->update([
            'checksum' => $checksum,
        ]);

        // Mark the job as completed
        $this->job->markCompleted();
    }

    /**
     * Scope to filter by database server
     */
    public function scopeForDatabaseServer($query, DatabaseServer $databaseServer)
    {
        return $query->where('database_server_id', $databaseServer->id);
    }

    /**
     * Scope to filter by completed status
     */
    public function scopeCompleted($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'completed'));
    }

    /**
     * Scope to filter by failed status
     */
    public function scopeFailed($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'failed'));
    }

    /**
     * Scope to filter by running status
     */
    public function scopeRunning($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'running'));
    }

    /**
     * Scope to filter by pending status
     */
    public function scopePending($query)
    {
        return $query->whereHas('job', fn ($q) => $q->where('status', 'pending'));
    }
}
