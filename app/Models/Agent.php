<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * @mixin IdeHelperAgent
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasApiTokens, HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    protected $fillable = [
        'name',
        'last_heartbeat_at',
        'version',
        'commit_hash',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, Agent>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<DatabaseServer, Agent>
     */
    public function databaseServers(): HasMany
    {
        return $this->hasMany(DatabaseServer::class);
    }

    /**
     * @return HasMany<AgentJob, Agent>
     */
    public function agentJobs(): HasMany
    {
        return $this->hasMany(AgentJob::class);
    }

    /**
     * Check if the agent is online (heartbeat within last 60 seconds).
     */
    public function isOnline(): bool
    {
        return $this->last_heartbeat_at !== null
            && $this->last_heartbeat_at->isAfter(now()->subMinutes(1));
    }

    /**
     * Connection status for display: 'online', 'offline', or 'never'.
     */
    public function connectionStatus(): string
    {
        if ($this->isOnline()) {
            return 'online';
        }

        return $this->last_heartbeat_at !== null ? 'offline' : 'never';
    }

    /**
     * How the agent's reported version compares to this server's, by major
     * and minor only: 'current', 'outdated', 'newer', 'legacy' (connected but
     * too old to report a version), 'dev' (an untagged build), 'unknown'
     * (this server is untagged) or 'never' (never connected).
     */
    public function versionStatus(): string
    {
        if ($this->last_heartbeat_at === null) {
            return 'never';
        }

        if ($this->version === null) {
            return 'legacy';
        }

        $agentMinor = self::minorVersion($this->version);
        if ($agentMinor === null) {
            return 'dev';
        }

        $serverMinor = self::minorVersion(config('app.version'));
        if ($serverMinor === null) {
            return 'unknown';
        }

        return match (version_compare($agentMinor, $serverMinor)) {
            -1 => 'outdated',
            1 => 'newer',
            default => 'current',
        };
    }

    public function needsUpdate(): bool
    {
        return in_array($this->versionStatus(), ['outdated', 'legacy'], true);
    }

    /**
     * What the version badge shows: the version, the short commit of an
     * untagged build, or 'Outdated' for an agent too old to report one.
     * Empty when the agent never connected.
     */
    public function versionLabel(): string
    {
        return match ($this->versionStatus()) {
            'never' => '',
            'legacy' => __('Outdated'),
            'dev' => $this->commit_hash !== null ? substr($this->commit_hash, 0, 7) : 'dev',
            default => 'v'.$this->version,
        };
    }

    /**
     * This server's version as "vX.Y.Z", or null on an untagged build.
     */
    public static function serverVersion(): ?string
    {
        $version = config('app.version');

        return self::minorVersion($version) !== null ? 'v'.ltrim($version, 'v') : null;
    }

    /**
     * The "major.minor" part of a semver string, or null when it is not one.
     */
    public static function minorVersion(?string $version): ?string
    {
        if ($version === null || ! preg_match('/^v?(\d+)\.(\d+)\.\d+/', $version, $matches)) {
            return null;
        }

        return $matches[1].'.'.$matches[2];
    }
}
