<?php

namespace App\Models;

use App\Enums\VolumeType;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\VolumeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperVolume
 */
class Volume extends Model
{
    /** @use HasFactory<VolumeFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // Type is immutable after creation — changing it would leave ghost config fields.
        static::updating(function (Volume $volume) {
            if ($volume->isDirty('type')) {
                throw new \RuntimeException('Changing volume type is not allowed.');
            }
        });
    }

    protected $fillable = [
        'name',
        'type',
        'config',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organization, Volume>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Backup, Volume>
     */
    public function backups(): BelongsToMany
    {
        return $this->belongsToMany(Backup::class);
    }

    /**
     * The snapshot copies stored on this volume.
     *
     * @return HasMany<SnapshotFile, Volume>
     */
    public function snapshotFiles(): HasMany
    {
        return $this->hasMany(SnapshotFile::class);
    }

    /**
     * Check if volume has any snapshot copies (making it immutable).
     */
    public function hasSnapshots(): bool
    {
        return $this->snapshotFiles()->exists();
    }

    /**
     * The configured storage limit for this volume in bytes, or null when the
     * volume has no limit. Stored under the config's `max_storage_bytes` key.
     * When the limit would be exceeded a backup is either failed before upload
     * or merely warned about, depending on {@see self::storageLimitIsNotifyOnly()}.
     * Nothing is pruned automatically.
     */
    public function maxStorageBytes(): ?int
    {
        $value = $this->config['max_storage_bytes'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    /**
     * Whether reaching the storage limit only sends a notification instead of
     * blocking the backup. Stored under the config's `max_storage_notify_only`
     * key; only meaningful when a limit is set.
     */
    public function storageLimitIsNotifyOnly(): bool
    {
        return (bool) ($this->config['max_storage_notify_only'] ?? false);
    }

    /**
     * Total size in bytes of the completed snapshots currently stored on this
     * volume — the baseline a new backup is added to when checking the limit.
     */
    public function usedStorageBytes(): int
    {
        // Prefer the aggregate eagerly loaded by VolumeQuery::buildFromParams so
        // listing many volumes stays a single query instead of N+1; fall back to
        // a direct sum when it wasn't loaded (e.g. a single volume in a job).
        if (array_key_exists('used_storage_bytes', $this->attributes)) {
            return (int) $this->attributes['used_storage_bytes'];
        }

        // The archive size lives on the snapshot (one archive, uploaded
        // unchanged to every volume), so each surviving copy contributes its
        // snapshot's size to this volume's usage.
        return (int) $this->snapshotFiles()
            ->completed()
            ->fileExists()
            ->join('snapshots', 'snapshot_files.snapshot_id', '=', 'snapshots.id')
            ->sum('snapshots.file_size');
    }

    /**
     * Get the volume type enum.
     */
    public function getVolumeType(): VolumeType
    {
        return VolumeType::from($this->type);
    }

    /**
     * Get config with sensitive fields decrypted.
     *
     * @return array<string, mixed>
     */
    public function getDecryptedConfig(): array
    {
        return $this->getVolumeType()->decryptSensitiveFields($this->config);
    }

    /**
     * Get a summary of the configuration for display (excludes sensitive fields).
     *
     * @return array<string, string>
     */
    public function getConfigSummary(): array
    {
        return $this->getVolumeType()->configSummary($this->config);
    }

    /**
     * Get config with sensitive fields removed (for storing in metadata/logs).
     *
     * @return array<string, mixed>
     */
    public function getSafeConfig(): array
    {
        return $this->getVolumeType()->stripSensitiveFields($this->config);
    }
}
