<?php

namespace App\Models;

use App\Enums\VolumeType;
use Database\Factories\VolumeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $id
 * @property string $name
 * @property string $type
 * @property array<array-key, mixed> $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Backup> $backups
 * @property-read int|null $backups_count
 * @property-read Collection<int, Snapshot> $snapshots
 * @property-read int|null $snapshots_count
 *
 * @method static VolumeFactory factory($count = null, $state = [])
 * @method static Builder<static>|Volume newModelQuery()
 * @method static Builder<static>|Volume newQuery()
 * @method static Builder<static>|Volume query()
 * @method static Builder<static>|Volume whereConfig($value)
 * @method static Builder<static>|Volume whereCreatedAt($value)
 * @method static Builder<static>|Volume whereId($value)
 * @method static Builder<static>|Volume whereName($value)
 * @method static Builder<static>|Volume whereType($value)
 * @method static Builder<static>|Volume whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Volume extends Model
{
    /** @use HasFactory<VolumeFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'name',
        'type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
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
