<?php

namespace App\Models;

use App\Enums\BackupJobStatus;
use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Enums\SnapshotFileStatus;
use App\Models\Scopes\DatabaseServerOrganizationScope;
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
 * @mixin IdeHelperSnapshot
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
        'filename',
        'file_size',
        'checksum',
        'started_at',
        'database_name',
        'comment',
        'locked',
        'deleting',
        'database_type',
        'compression_type',
        'method',
        'metadata',
        'triggered_by_user_id',
    ];

    /**
     * The column defaults, so a snapshot just created reads them too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locked' => false,
        'deleting' => false,
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'file_size' => 'integer',
            'locked' => 'boolean',
            'deleting' => 'boolean',
            'database_type' => DatabaseType::class,
            'metadata' => 'array',
            'compression_type' => CompressionType::class,
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
     * Get volume info from metadata (first volume for multi-volume snapshots).
     * Falls back to the legacy single `volume` key for older snapshots.
     *
     * @return array<string, mixed>
     */
    public function getVolumeMetadata(): array
    {
        return $this->getVolumesMetadata()[0] ?? [
            'type' => null,
            'config' => null,
        ];
    }

    /**
     * Get all volume infos from metadata, wrapping the legacy single `volume`
     * key for snapshots created before multi-volume support.
     *
     * @return list<array<string, mixed>>
     */
    public function getVolumesMetadata(): array
    {
        $volumes = $this->metadata['volumes'] ?? null;
        if (is_array($volumes)) {
            return array_values($volumes);
        }

        $legacy = $this->metadata['volume'] ?? null;

        return is_array($legacy) ? [$legacy] : [];
    }

    /**
     * Name of the agent that ran this backup, or null if it ran on the app's own queue.
     */
    public function getAgentName(): ?string
    {
        return $this->metadata['agent']['name'] ?? null;
    }

    protected static function booted(): void
    {
        // Tenancy is inherited from the database server the snapshot was taken from.
        static::addGlobalScope(new DatabaseServerOrganizationScope('database_server_id'));

        // Files on storage are removed by the actions before the row goes;
        // this only keeps the rows that point at the snapshot consistent.
        static::deleting(function (Snapshot $snapshot) {
            // The copy rows would cascade at the DB level, but delete them
            // explicitly so the restores' snapshot_file_id nullOnDelete fires
            // consistently across drivers.
            $snapshot->files()->delete();

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
     * @return HasMany<SnapshotFile, Snapshot>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SnapshotFile::class);
    }

    /**
     * The copy a restore or download should read from when the user didn't
     * pick one: the first successfully uploaded copy still present on its volume.
     */
    public function primaryFile(): ?SnapshotFile
    {
        return $this->files()->completed()->fileExists()->oldest('id')->first();
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
     * True when at least one uploaded copy is still present on its volume.
     * Losing one copy while another remains is not a data loss.
     */
    public function hasExistingFile(): bool
    {
        return $this->files->contains(
            fn (SnapshotFile $file) => $file->status === SnapshotFileStatus::Completed && $file->file_exists
        );
    }

    /**
     * True when the archive was uploaded but every copy has since gone
     * missing from its volume — the read-side counterpart of scopeFileMissing.
     * A snapshot still uploading (or one that never uploaded) is not missing.
     */
    public function hasMissingFile(): bool
    {
        $uploaded = $this->files->where('status', SnapshotFileStatus::Completed);

        return $uploaded->isNotEmpty() && ! $uploaded->contains(fn (SnapshotFile $file) => $file->file_exists);
    }

    /**
     * When the copies were last checked against their volumes, or null if no
     * copy has ever been verified.
     */
    public function lastVerifiedAt(): ?Carbon
    {
        return $this->files->max('file_verified_at');
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

    /**
     * Scope to only snapshots whose backup job completed successfully.
     *
     * @param  Builder<Snapshot>  $query
     * @return Builder<Snapshot>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereRelation('job', 'status', BackupJobStatus::Completed);
    }

    /**
     * Scope to snapshots a user may delete: unlocked, not already queued for
     * deletion, with a finished backup job.
     *
     * @param  Builder<Snapshot>  $query
     * @return Builder<Snapshot>
     */
    public function scopeDeletable(Builder $query): Builder
    {
        return $query
            ->where('locked', false)
            ->where('deleting', false)
            ->whereHas('job', fn (Builder $q) => $q->whereIn('status', [
                BackupJobStatus::Completed,
                BackupJobStatus::Failed,
                BackupJobStatus::Cancelled,
            ]));
    }

    /**
     * Scope to snapshots with at least one copy still present on a volume.
     *
     * @param  Builder<Snapshot>  $query
     * @return Builder<Snapshot>
     */
    public function scopeFileExists(Builder $query): Builder
    {
        return $query->whereHas('files', function (Builder $query): void {
            /** @var Builder<SnapshotFile> $query */
            $query->completed()->fileExists();
        });
    }

    /**
     * Scope to snapshots that were uploaded but whose every copy has since
     * gone missing. A snapshot that never uploaded anywhere is a failed
     * backup, not a missing file, so it is excluded.
     *
     * @param  Builder<Snapshot>  $query
     * @return Builder<Snapshot>
     */
    public function scopeFileMissing(Builder $query): Builder
    {
        return $query
            ->whereHas('files', function (Builder $query): void {
                /** @var Builder<SnapshotFile> $query */
                $query->completed();
            })
            ->whereDoesntHave('files', function (Builder $query): void {
                /** @var Builder<SnapshotFile> $query */
                $query->completed()->fileExists();
            });
    }
}
