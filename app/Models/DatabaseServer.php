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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $host
 * @property int $port
 * @property DatabaseType $database_type
 * @property string|null $sqlite_path
 * @property string $username
 * @property string $password
 * @property array<string>|null $database_names
 * @property bool $backup_all_databases
 * @property string|null $description
 * @property bool $backups_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Backup|null $backup
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
        'sqlite_path',
        'username',
        'password',
        'database_names',
        'backup_all_databases',
        'description',
        'backups_enabled',
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
}
