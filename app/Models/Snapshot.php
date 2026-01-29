<?php

namespace App\Models;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\Formatters;
use Database\Factories\SnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $database_server_id
 * @property string $backup_id
 * @property string $volume_id
 * @property string $filename
 * @property int $file_size
 * @property string|null $checksum
 * @property Carbon $started_at
 * @property string $database_name
 * @property DatabaseType $database_type
 * @property CompressionType $compression_type
 * @property string $method
 * @property array<string, mixed>|null $metadata
 * @property string|null $triggered_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $backup_job_id
 * @property-read Backup $backup
 * @property-read DatabaseServer $databaseServer
 * @property-read BackupJob $job
 * @property-read User|null $triggeredBy
 * @property-read Volume $volume
 *
 * @method static Builder<static>|Snapshot forDatabaseServer(DatabaseServer $databaseServer)
 * @method static Builder<static>|Snapshot newModelQuery()
 * @method static Builder<static>|Snapshot newQuery()
 * @method static Builder<static>|Snapshot query()
 * @method static Builder<static>|Snapshot whereBackupId($value)
 * @method static Builder<static>|Snapshot whereBackupJobId($value)
 * @method static Builder<static>|Snapshot whereChecksum($value)
 * @method static Builder<static>|Snapshot whereCompressionType($value)
 * @method static Builder<static>|Snapshot whereCreatedAt($value)
 * @method static Builder<static>|Snapshot whereDatabaseName($value)
 * @method static Builder<static>|Snapshot whereDatabaseServerId($value)
 * @method static Builder<static>|Snapshot whereDatabaseType($value)
 * @method static Builder<static>|Snapshot whereFileSize($value)
 * @method static Builder<static>|Snapshot whereFilename($value)
 * @method static Builder<static>|Snapshot whereId($value)
 * @method static Builder<static>|Snapshot whereMetadata($value)
 * @method static Builder<static>|Snapshot whereMethod($value)
 * @method static Builder<static>|Snapshot whereStartedAt($value)
 * @method static Builder<static>|Snapshot whereTriggeredByUserId($value)
 * @method static Builder<static>|Snapshot whereUpdatedAt($value)
 * @method static Builder<static>|Snapshot whereVolumeId($value)
 * @method static SnapshotFactory factory($count = null, $state = [])
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Restore> $restores
 * @property-read int|null $restores_count
 *
 * @mixin \Eloquent
 */
class Snapshot extends Model
{
    /** @use HasFactory<SnapshotFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'backup_job_id',
        'database_server_id',
        'backup_id',
        'volume_id',
        'filename',
        'file_size',
        'checksum',
        'started_at',
        'database_name',
        'database_type',
        'compression_type',
        'method',
        'metadata',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'file_size' => 'integer',
            'database_type' => DatabaseType::class,
            'metadata' => 'array',
            'compression_type' => CompressionType::class,
        ];
    }

    /**
     * Generate metadata array for a snapshot.
     *
     * @return array{database_server: array{host: string|null, port: int|null, username: string|null, database_name: string}, volume: array{type: string, config: array<string, mixed>}}
     */
    public static function generateMetadata(DatabaseServer $server, string $databaseName, Volume $volume): array
    {
        return [
            'database_server' => [
                'host' => $server->host,
                'port' => $server->port,
                'username' => $server->username,
                'database_name' => $databaseName,
            ],
            'volume' => [
                'type' => $volume->type,
                'config' => $volume->config,
            ],
        ];
    }

    /**
     * Get database server info from metadata.
     *
     * @return array{host: string|null, port: int|null, username: string|null, database_name: string|null}
     */
    public function getDatabaseServerMetadata(): array
    {
        return $this->metadata['database_server'] ?? [
            'host' => null,
            'port' => null,
            'username' => null,
            'database_name' => null,
        ];
    }

    /**
     * Get volume info from metadata.
     *
     * @return array{type: string|null, config: array<string, mixed>|null}
     */
    public function getVolumeMetadata(): array
    {
        return $this->metadata['volume'] ?? [
            'type' => null,
            'config' => null,
        ];
    }

    protected static function booted(): void
    {
        // Delete the backup file, associated restores and job when snapshot is deleted
        static::deleting(function (Snapshot $snapshot) {
            $snapshot->deleteBackupFile();

            // Delete restores first (this triggers their booted method to delete their jobs)
            foreach ($snapshot->restores as $restore) {
                $restore->delete();
            }

            // Delete the snapshot's own job
            $snapshot->job->delete();
        });
    }

    /**
     * @return BelongsTo<DatabaseServer, Snapshot>
     */
    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    /**
     * @return BelongsTo<Backup, Snapshot>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    /**
     * @return BelongsTo<Volume, Snapshot>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return BelongsTo<User, Snapshot>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /**
     * @return BelongsTo<BackupJob, Snapshot>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    /**
     * @return HasMany<Restore, Snapshot>
     */
    public function restores(): HasMany
    {
        return $this->hasMany(Restore::class);
    }

    /**
     * Get human-readable file size
     */
    public function getHumanFileSize(): string
    {
        return Formatters::humanFileSize($this->file_size);
    }

    /**
     * Delete the backup file from the volume
     */
    public function deleteBackupFile(): bool
    {
        // Skip if no filename (backup file was never created)
        if (empty($this->filename)) {
            return false;
        }

        try {
            // Get the filesystem for this volume
            $filesystemProvider = app(FilesystemProvider::class);
            $filesystem = $filesystemProvider->getForVolume($this->volume);

            // Delete the file if it exists
            if ($filesystem->fileExists($this->filename)) {
                $filesystem->delete($this->filename);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            // Log the error but don't throw to prevent deletion cascade failure
            logger()->error('Failed to delete backup file for snapshot', [
                'snapshot_id' => $this->id,
                'filename' => $this->filename,
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
     *
     * @param  Builder<Snapshot>  $query
     * @return Builder<Snapshot>
     */
    public function scopeForDatabaseServer(Builder $query, DatabaseServer $databaseServer): Builder
    {
        return $query->where('database_server_id', $databaseServer->id);
    }
}
