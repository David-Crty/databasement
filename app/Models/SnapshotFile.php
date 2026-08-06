<?php

namespace App\Models;

use App\Enums\SnapshotFileStatus;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use Database\Factories\SnapshotFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored copy of a snapshot's archive on a specific volume. A snapshot
 * has one row per target volume; the archive itself (filename, size,
 * checksum) is identical across copies and lives on the Snapshot.
 *
 * @mixin IdeHelperSnapshotFile
 */
class SnapshotFile extends Model
{
    /** @use HasFactory<SnapshotFileFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'snapshot_id',
        'volume_id',
        'status',
        'file_exists',
        'file_verified_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'file_exists' => 'boolean',
            'file_verified_at' => 'datetime',
            'status' => SnapshotFileStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Snapshot, SnapshotFile>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    /**
     * @return BelongsTo<Volume, SnapshotFile>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * Scope to copies whose upload completed successfully.
     *
     * @param  Builder<SnapshotFile>  $query
     * @return Builder<SnapshotFile>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('snapshot_files.status', SnapshotFileStatus::Completed);
    }

    /**
     * Scope to copies whose file still exists on the volume.
     *
     * @param  Builder<SnapshotFile>  $query
     * @return Builder<SnapshotFile>
     */
    public function scopeFileExists(Builder $query): Builder
    {
        return $query->where('snapshot_files.file_exists', true);
    }

    /**
     * The path of this copy on its volume. The same archive is uploaded to
     * every target volume under the same name, so the name lives on the
     * snapshot and is never repeated per copy.
     */
    public function storedFilename(): string
    {
        return (string) $this->snapshot->filename;
    }

    /**
     * True when this copy still has (or may still have) a file on its volume
     * that has to be removed before the snapshot record can go away. Covers
     * uploaded copies as well as copies whose removal was already delegated to
     * an agent but never confirmed, so a retry picks them up again.
     */
    public function needsVolumeCleanup(): bool
    {
        return in_array($this->status, [
            SnapshotFileStatus::Completed,
            SnapshotFileStatus::Deleting,
            SnapshotFileStatus::DeletionFailed,
        ], true);
    }

    /**
     * Delete this copy's file from its volume.
     */
    public function deleteFromVolume(): bool
    {
        $filename = $this->storedFilename();

        // Skip if no filename (backup file was never created)
        if ($filename === '') {
            return false;
        }

        try {
            $filesystemProvider = app(FilesystemProvider::class);
            $filesystem = $filesystemProvider->getForVolume($this->volume);

            if ($filesystem->fileExists($filename)) {
                $filesystem->delete($filename);

                FilesystemSupport::deleteEmptyParentDirectories($filesystem, $filename, [
                    'snapshot_id' => $this->snapshot_id,
                ]);

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            // Log the error but don't throw to prevent deletion cascade failure
            logger()->error('Failed to delete backup file for snapshot', [
                'snapshot_id' => $this->snapshot_id,
                'volume_id' => $this->volume_id,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
