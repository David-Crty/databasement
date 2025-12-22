<?php

namespace App\Models;

use Database\Factories\DatabaseServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $name
 * @property string $host
 * @property int $port
 * @property string $database_type
 * @property string $username
 * @property string $password
 * @property string|null $database_name
 * @property bool $backup_all_databases
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Backup|null $backup
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Snapshot> $snapshots
 * @property-read int|null $snapshots_count
 *
 * @method static \Database\Factories\DatabaseServerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereDatabaseName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereDatabaseType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer wherePort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DatabaseServer whereUsername($value)
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
        'database_name',
        'backup_all_databases',
        'description',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'backup_all_databases' => 'boolean',
            'password' => 'encrypted',
        ];
    }

    public function backup(): HasOne
    {
        return $this->hasOne(Backup::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }
}
