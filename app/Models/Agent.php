<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property Carbon|null $last_heartbeat_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DatabaseServer> $databaseServers
 * @property-read int|null $database_servers_count
 * @property-read Collection<int, AgentJob> $agentJobs
 * @property-read int|null $agent_jobs_count
 *
 * @method static AgentFactory factory($count = null, $state = [])
 * @method static Builder<static>|Agent newModelQuery()
 * @method static Builder<static>|Agent newQuery()
 * @method static Builder<static>|Agent query()
 * @method static Builder<static>|Agent whereId($value)
 * @method static Builder<static>|Agent whereName($value)
 * @method static Builder<static>|Agent whereLastHeartbeatAt($value)
 * @method static Builder<static>|Agent whereCreatedAt($value)
 * @method static Builder<static>|Agent whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasApiTokens, HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
        ];
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
