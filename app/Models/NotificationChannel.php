<?php

namespace App\Models;

use App\Enums\NotificationChannelType;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $id
 * @property string $name
 * @property NotificationChannelType $type
 * @property array<string, mixed> $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DatabaseServer> $databaseServers
 * @property-read int|null $database_servers_count
 *
 * @method static NotificationChannelFactory factory($count = null, $state = [])
 * @method static Builder<static>|NotificationChannel newModelQuery()
 * @method static Builder<static>|NotificationChannel newQuery()
 * @method static Builder<static>|NotificationChannel query()
 *
 * @mixin \Eloquent
 */
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
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
            'type' => NotificationChannelType::class,
            'config' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<DatabaseServer, NotificationChannel>
     */
    public function databaseServers(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseServer::class, 'database_server_notification_channel');
    }

    /**
     * Get config with sensitive fields decrypted.
     *
     * @return array<string, mixed>
     */
    public function getDecryptedConfig(): array
    {
        $config = $this->config;

        foreach ($this->type->sensitiveFields() as $field) {
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
        return $this->type->configSummary($this->config);
    }
}
