<?php

namespace App\Models;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\EncryptionException;
use Database\Factories\DatabaseServerFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $host
 * @property int $port
 * @property DatabaseType $database_type
 * @property string $username
 * @property string $password
 * @property array<string>|null $database_names
 * @property array<string, mixed>|null $extra_config
 * @property bool $backup_all_databases
 * @property string|null $description
 * @property bool $backups_enabled
 * @property string|null $ssh_config_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Backup|null $backup
 * @property-read DatabaseServerSshConfig|null $sshConfig
 * @property-read Collection<int, Snapshot> $snapshots
 * @property-read int|null $snapshots_count
 *
 * @method static DatabaseServerFactory factory($count = null, $state = [])
 * @method static Builder<static>|DatabaseServer newModelQuery()
 * @method static Builder<static>|DatabaseServer newQuery()
 * @method static Builder<static>|DatabaseServer query()
 * @method static Builder<static>|DatabaseServer whereCreatedAt($value)
 * @method static Builder<static>|DatabaseServer whereDatabaseNames($value)
 * @method static Builder<static>|DatabaseServer whereDatabaseType($value)
 * @method static Builder<static>|DatabaseServer whereDescription($value)
 * @method static Builder<static>|DatabaseServer whereHost($value)
 * @method static Builder<static>|DatabaseServer whereId($value)
 * @method static Builder<static>|DatabaseServer whereName($value)
 * @method static Builder<static>|DatabaseServer wherePassword($value)
 * @method static Builder<static>|DatabaseServer wherePort($value)
 * @method static Builder<static>|DatabaseServer whereUpdatedAt($value)
 * @method static Builder<static>|DatabaseServer whereUsername($value)
 * @method static Builder<static>|DatabaseServer whereBackupAllDatabases($value)
 * @method static Builder<static>|DatabaseServer whereBackupsEnabled($value)
 *
 * @mixin \Eloquent
 */
class DatabaseServer extends Model
{
    /** @use HasFactory<DatabaseServerFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        // Delete snapshots through Eloquent to trigger their deleting events
        // (which clean up associated BackupJobs and Restores)
        static::deleting(function (DatabaseServer $server) {
            foreach ($server->snapshots as $snapshot) {
                $snapshot->delete();
            }
        });
    }

    protected $fillable = [
        'name',
        'host',
        'port',
        'database_type',
        'username',
        'password',
        'database_names',
        'backup_all_databases',
        'description',
        'backups_enabled',
        'ssh_config_id',
        'extra_config',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'database_type' => DatabaseType::class,
            'backup_all_databases' => 'boolean',
            'backups_enabled' => 'boolean',
            'password' => 'encrypted',
            'database_names' => 'array',
            'extra_config' => 'array',
        ];
    }

    /**
     * @return HasOne<Backup, DatabaseServer>
     */
    public function backup(): HasOne
    {
        return $this->hasOne(Backup::class);
    }

    /**
     * @return HasMany<Snapshot, DatabaseServer>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    /**
     * @return BelongsTo<DatabaseServerSshConfig, DatabaseServer>
     */
    public function sshConfig(): BelongsTo
    {
        return $this->belongsTo(DatabaseServerSshConfig::class, 'ssh_config_id');
    }

    /**
     * Get the decrypted password with proper exception handling.
     *
     * @throws EncryptionException
     */
    public function getDecryptedPassword(): string
    {
        try {
            return $this->password ?? '';
        } catch (DecryptException $e) { // @phpstan-ignore catch.neverThrown (DecryptException is thrown by Laravel's encrypted cast)
            throw new EncryptionException(
                'Unable to decrypt database password. The application key (APP_KEY) may have changed. Please update the password in the database server settings.',
                previous: $e
            );
        }
    }

    /**
     * Check if this server requires an SSH tunnel for connections.
     * SQLite servers never need SSH tunnels since they use local file paths.
     */
    public function requiresSshTunnel(): bool
    {
        return $this->database_type !== DatabaseType::SQLITE
            && $this->ssh_config_id !== null;
    }

    /**
     * Check if this server requires SFTP file transfer for backups/restores.
     * Only applies to SQLite servers accessed via SSH.
     */
    public function requiresSftpTransfer(): bool
    {
        return $this->database_type === DatabaseType::SQLITE
            && $this->ssh_config_id !== null;
    }

    /**
     * Create a temporary DatabaseServer instance for connection testing.
     * This is not persisted to the database.
     *
     * @param  array<string, mixed>  $config
     */
    public static function forConnectionTest(array $config, ?DatabaseServerSshConfig $sshConfig = null): self
    {
        $server = new self;
        $server->host = $config['host'] ?? '';
        $server->port = (int) ($config['port'] ?? 3306);
        $server->database_type = $config['database_type'] ?? 'mysql';
        $server->username = $config['username'] ?? '';
        $server->password = $config['password'] ?? '';
        $server->database_names = $config['database_names'] ?? null;
        $server->extra_config = $config['extra_config'] ?? null;

        if ($sshConfig !== null) {
            $server->ssh_config_id = 'temp';
            $server->setRelation('sshConfig', $sshConfig);
        }

        return $server;
    }

    /**
     * Get a short connection label for display (filename for SQLite, host:port for client-server).
     */
    public function getConnectionLabel(): string
    {
        if ($this->database_type === DatabaseType::SQLITE) {
            return implode(', ', array_map('basename', $this->database_names ?? []));
        }

        return "{$this->host}:{$this->port}";
    }

    /**
     * Get full connection details for popover/tooltip (full paths for SQLite, host:port for client-server).
     */
    public function getConnectionDetails(): string
    {
        if ($this->database_type === DatabaseType::SQLITE) {
            return implode(', ', $this->database_names ?? []);
        }

        return "{$this->host}:{$this->port}";
    }

    /**
     * Get a type-specific config value from extra_config.
     */
    public function getExtraConfig(string $key, mixed $default = null): mixed
    {
        return $this->extra_config[$key] ?? $default;
    }

    /**
     * Get SSH display name if configured (tunnel or SFTP), null otherwise.
     */
    public function getSshDisplayName(): ?string
    {
        if ($this->ssh_config_id === null || $this->sshConfig === null) {
            return null;
        }

        return $this->sshConfig->getDisplayName();
    }
}
