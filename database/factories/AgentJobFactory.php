<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Snapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentJob>
 */
class AgentJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'snapshot_id' => Snapshot::factory(),
            'status' => 'pending',
            'payload' => [
                'database' => [
                    'type' => 'mysql',
                    'host' => 'localhost',
                    'port' => 3306,
                    'username' => 'root',
                    'password' => 'secret',
                    'database_name' => 'myapp',
                ],
                'volume' => [
                    'type' => 'local',
                    'config' => ['path' => '/backups'],
                ],
                'compression' => [
                    'type' => 'gzip',
                    'level' => 6,
                ],
                'backup_path' => 'backups/2026/02',
                'server_name' => 'Test Server',
                'dump_extension' => 'sql',
            ],
            'max_attempts' => 3,
        ];
    }

    /**
     * Configure the job as claimed by an agent.
     */
    public function claimed(?Agent $agent = null): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent?->id ?? Agent::factory(),
            'status' => 'claimed',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
            'attempts' => 1,
        ]);
    }

    /**
     * Configure the job as running.
     */
    public function running(?Agent $agent = null): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent?->id ?? Agent::factory(),
            'status' => 'running',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
            'attempts' => 1,
        ]);
    }

    /**
     * Configure the job as completed.
     */
    public function completed(?Agent $agent = null): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent?->id ?? Agent::factory(),
            'status' => 'completed',
            'claimed_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'attempts' => 1,
        ]);
    }

    /**
     * Configure the job as failed.
     */
    public function failed(?Agent $agent = null): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent?->id ?? Agent::factory(),
            'status' => 'failed',
            'claimed_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'error_message' => 'Connection refused',
            'attempts' => 1,
        ]);
    }

    /**
     * Configure the job with an expired lease.
     */
    public function expiredLease(?Agent $agent = null): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent?->id ?? Agent::factory(),
            'status' => 'claimed',
            'claimed_at' => now()->subMinutes(10),
            'lease_expires_at' => now()->subMinutes(5),
            'attempts' => 1,
        ]);
    }
}
