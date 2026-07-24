<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperOrganization
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * When true, backup files on storage are preserved while cascading the
     * deletion of the organization's servers and volumes (only DB records go).
     */
    public bool $skipFileCleanup = false;

    protected static function booted(): void
    {
        // Cascade-delete owned resources through Eloquent so backup files and
        // related jobs/restores are cleaned up. The database-level cascade on
        // organization_id would remove the rows but skip these side effects.
        static::deleting(function (Organization $organization) {
            foreach ($organization->databaseServers()->withoutGlobalScope(OrganizationScope::class)->get() as $server) {
                $server->skipFileCleanup = $organization->skipFileCleanup;
                $server->delete();
            }

            foreach ($organization->volumes()->withoutGlobalScope(OrganizationScope::class)->get() as $volume) {
                $volume->skipFileCleanup = $organization->skipFileCleanup;
                $volume->delete();
            }
        });
    }

    protected $fillable = [
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, Organization>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<DatabaseServer, Organization>
     */
    public function databaseServers(): HasMany
    {
        return $this->hasMany(DatabaseServer::class);
    }

    /**
     * @return HasMany<Volume, Organization>
     */
    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    /**
     * @return HasMany<Agent, Organization>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * @return HasMany<DatabaseServerSshConfig, Organization>
     */
    public function sshConfigs(): HasMany
    {
        return $this->hasMany(DatabaseServerSshConfig::class);
    }

    /**
     * Get the default organization.
     */
    public static function default(): self
    {
        return static::where('is_default', true)->firstOrFail();
    }
}
