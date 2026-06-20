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
}
