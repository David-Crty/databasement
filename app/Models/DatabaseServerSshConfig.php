<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Database\Factories\DatabaseServerSshConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperDatabaseServerSshConfig
 */
class DatabaseServerSshConfig extends Model
{
    /** @use HasFactory<DatabaseServerSshConfigFactory> */
    use HasFactory;

    use HasUlids;

    /** @var array<string> Sensitive fields that contain encrypted data */
    public const SENSITIVE_FIELDS = ['password', 'private_key', 'key_passphrase'];

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    protected $fillable = [
        'host',
        'port',
        'username',
        'auth_type',
        'password',
        'private_key',
        'key_passphrase',
        'organization_id',
    ];

    protected $hidden = [
        'password',
        'private_key',
        'key_passphrase',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'private_key' => 'encrypted',
            'key_passphrase' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Organization, DatabaseServerSshConfig>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<DatabaseServer, DatabaseServerSshConfig>
     */
    public function databaseServers(): HasMany
    {
        return $this->hasMany(DatabaseServer::class, 'ssh_config_id');
    }

    /**
     * Get display name in format: username@host:port
     */
    public function getDisplayName(): string
    {
        return "{$this->username}@{$this->host}:{$this->port}";
    }

    /**
     * Get all config values with sensitive fields decrypted.
     * Used when establishing SSH connections.
     *
     * @return array<string, mixed>
     */
    public function getDecrypted(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_type' => $this->auth_type,
            'password' => $this->password,
            'private_key' => $this->private_key,
            'key_passphrase' => $this->key_passphrase,
        ];
    }

    /**
     * Get config with sensitive fields removed (for logging/display).
     *
     * @return array<string, mixed>
     */
    public function getSafe(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_type' => $this->auth_type,
        ];
    }
}
