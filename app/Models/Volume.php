<?php

namespace App\Models;

use App\Enums\VolumeType;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\VolumeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * @mixin IdeHelperVolume
 */
class Volume extends Model
{
    /** @use HasFactory<VolumeFactory> */
    use HasFactory;

    use HasUlids;

    public bool $skipFileCleanup = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // Delete snapshots through Eloquent to trigger their deleting events
        // (which clean up associated BackupJobs, Restores, and backup files)
        // Type is immutable after creation — changing it would leave ghost config fields.
        static::updating(function (Volume $volume) {
            if ($volume->isDirty('type')) {
                throw new \RuntimeException('Changing volume type is not allowed.');
            }
        });

        static::deleting(function (Volume $volume) {
            foreach ($volume->snapshots as $snapshot) {
                $snapshot->skipFileCleanup = $volume->skipFileCleanup;
                $snapshot->delete();
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
     * @return HasMany<Backup, Volume>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * @return HasMany<Snapshot, Volume>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    /**
     * Check if volume has any snapshots (making it immutable).
     */
    public function hasSnapshots(): bool
    {
        return $this->snapshots()->exists();
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
        $config = $this->config;
        $volumeType = $this->getVolumeType();

        foreach ($volumeType->sensitiveFields() as $field) {
            if (! empty($config[$field])) {
                try {
                    $config[$field] = Crypt::decryptString($config[$field]);
                } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                    // Value is not encrypted (legacy data), return as-is
                }
            }
        }

        return $config;
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
        $config = $this->config;
        $volumeType = $this->getVolumeType();

        foreach ($volumeType->sensitiveFields() as $field) {
            unset($config[$field]);
        }

        return $config;
    }
}
