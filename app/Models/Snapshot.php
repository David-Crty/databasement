<?php

namespace App\Models;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Models\Scopes\OrganizationScope;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\CurrentOrganization;
use App\Support\Formatters;
use Database\Factories\SnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperSnapshot
 */
class Snapshot extends Model
{
    /** @use HasFactory<SnapshotFactory> */
    use HasFactory;

    use HasUlids;

    public bool $skipFileCleanup = false;

    protected $fillable = [
        'backup_job_id',
        'database_server_id',
        'backup_id',
        'volume_id',
        'filename',
        'file_size',
        'file_exists',
        'file_verified_at',
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
            'file_exists' => 'boolean',
            'file_verified_at' => 'datetime',
            'database_type' => DatabaseType::class,
            'metadata' => 'array',
            'compression_type' => CompressionType::class,
        ];
    }

    /**
     * Generate metadata array for a snapshot.
     * Sensitive fields (passwords) are excluded from the volume config.
     *
     * @return array{database_server: array{host: string|null, port: int|null, username: string|null, database_name: string, ssh_tunnel: array{enabled: bool, host?: string, port?: int, username?: string, auth_type?: string}}, volume: array{type: string, config: array<string, mixed>}, dump_format?: string}
     */
    public static function generateMetadata(DatabaseServer $server, string $databaseName, Volume $volume): array
    {
        $sshTunnel = ['enabled' => false];
        if ($server->requiresSshTunnel() && $server->sshConfig !== null) {
            $safeSshConfig = $server->sshConfig->getSafe();
            $sshTunnel = [
                'enabled' => true,
                'host' => $safeSshConfig['host'] ?? null,
                'port' => $safeSshConfig['port'] ?? 22,
                'username' => $safeSshConfig['username'] ?? null,
                'auth_type' => $safeSshConfig['auth_type'] ?? 'password',
            ];
        }

        $metadata = [
            'database_server' => [
                'host' => $server->host,
                'port' => $server->port,
                'username' => $server->username,
                'database_name' => $databaseName,
                'ssh_tunnel' => $sshTunnel,
            ],
            'volume' => [
                'type' => $volume->type,
                'config' => $volume->getSafeConfig(),
            ],
        ];

        // Record dump format for restore-time dispatch. Absent → plain (default for legacy snapshots).
        if ($server->database_type === DatabaseType::POSTGRESQL
            && $server->getExtraConfig('dump_format') === 'custom') {
            $metadata['dump_format'] = 'custom';
        }

        return $metadata;
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
            if (! $snapshot->skipFileCleanup) {
                $snapshot->deleteBackupFile();
            }

            // Delete restores first (this triggers their booted method to delete their jobs)
            foreach ($snapshot->restores as $restore) {
                $restore->delete();
            }

            // Delete the snapshot's own job
            $snapshot->job->delete();
        });
    }

    /**
     * Scope to filter snapshots by the current organization.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentOrg(Builder $query): Builder
    {
        $orgId = app(CurrentOrganization::class)->id();

        return $query->whereHas('databaseServer', function (Builder $sq) use ($orgId) {
            $sq->withoutGlobalScope(OrganizationScope::class)
                ->whereRaw('organization_id = ?', [$orgId]);
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
