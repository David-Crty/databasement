<?php

namespace App\Models;

use App\Enums\SnapshotFileStatus;
use App\Enums\VolumeType;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use Database\Factories\SnapshotFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

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
     * Scope to copies a remote agent can read: every volume type except the
     * app's own local disk.
     *
     * @param  Builder<SnapshotFile>  $query
     * @return Builder<SnapshotFile>
     */
    public function scopeReachableByAgent(Builder $query): Builder
    {
        return $query->whereHas('volume', function (Builder $volume): void {
            /** @var Builder<Volume> $volume */
            $volume->where('type', '!=', VolumeType::LOCAL->value);
        });
    }

    public function markUploaded(): void
    {
        $this->update([
            'status' => SnapshotFileStatus::Completed,
            'file_exists' => true,
            'file_verified_at' => now(),
            'error' => null,
        ]);
    }

    public function markUploadFailed(?string $error): void
    {
        $this->update([
            'status' => SnapshotFileStatus::Failed,
            'error' => $error,
        ]);
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
     * Delete each copy's file from its volume. A copy whose file is gone stops
     * claiming it, so it is no longer offered for download or restore while
     * its row outlives the file; one whose file could not be deleted keeps
     * the error on its row.
     *
     * @param  Collection<int, SnapshotFile>  $files
     * @return Collection<int, SnapshotFile> The copies whose file is still on its volume
     */
    public static function removeEachFromVolume(Collection $files): Collection
    {
        return $files->reject(function (SnapshotFile $file): bool {
            try {
                $file->removeFromVolume();
                $file->update(['file_exists' => false, 'error' => null]);

                return true;
            } catch (Throwable $e) {
                $file->update(['error' => $e->getMessage()]);

                return false;
            }
        })->values();
    }

    /**
     * Delete this copy's file from its volume. Returns false when there was
     * no file to delete, which leaves nothing behind either.
     *
     * @throws Throwable When the volume cannot be reached or refuses the deletion
     */
    public function removeFromVolume(): bool
    {
        $filename = $this->storedFilename();

        // Skip if no filename (backup file was never created)
        if ($filename === '') {
            return false;
        }

        return FilesystemSupport::deleteFile(
            app(FilesystemProvider::class)->getForVolume($this->volume),
            $filename,
            ['snapshot_id' => $this->snapshot_id],
        );
    }
}
