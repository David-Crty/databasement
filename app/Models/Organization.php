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
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
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

    /**
     * Check if the organization has any resources (servers, volumes, agents).
     */
    public function hasResources(): bool
    {
        return $this->databaseServers()->withoutGlobalScope(OrganizationScope::class)->exists()
            || $this->volumes()->withoutGlobalScope(OrganizationScope::class)->exists()
            || $this->agents()->withoutGlobalScope(OrganizationScope::class)->exists();
    }
}
