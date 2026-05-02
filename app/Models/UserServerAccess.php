<?php

namespace App\Models;

use Database\Factories\UserServerAccessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $database_server_id
 * @property array<int, string>|null $allowed_databases
 * @property bool $can_download
 * @property bool $can_backup
 * @property bool $can_restore
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DatabaseServer $databaseServer
 * @property-read User $user
 *
 * @method static UserServerAccessFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class UserServerAccess extends Model
{
    /** @use HasFactory<UserServerAccessFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'database_server_id',
        'allowed_databases',
        'can_download',
        'can_backup',
        'can_restore',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_databases' => 'array',
            'can_download' => 'boolean',
            'can_backup' => 'boolean',
            'can_restore' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, UserServerAccess>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DatabaseServer, UserServerAccess>
     */
    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    public function allowsDatabase(string $databaseName): bool
    {
        if ($this->allowed_databases === null) {
            return true;
        }

        return in_array($databaseName, $this->allowed_databases, strict: true);
    }
}
