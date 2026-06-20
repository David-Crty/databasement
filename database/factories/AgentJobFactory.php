<?php

namespace Database\Factories;

use App\Enums\DatabaseSelectionMode;
use App\Models\Agent;
use App\Models\AgentJob;
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
            'type' => AgentJob::TYPE_BACKUP,
            'snapshot_id' => Snapshot::factory(),
            'status' => AgentJob::STATUS_PENDING,
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
     * Configure the factory to set database_server_id from snapshot.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (AgentJob $job) {
            if ($job->database_server_id === null && $job->snapshot_id !== null) {
                $job->update([
                    'database_server_id' => $job->snapshot->database_server_id,
                ]);
            }
        });
    }

    /**
     * Configure the job as a discovery job.
     */
    public function discover(): static
    {
        return $this->state(fn () => [
            'type' => AgentJob::TYPE_DISCOVER,
            'snapshot_id' => null,
            'payload' => [
                'type' => 'discover',
                'database' => [
                    'type' => 'mysql',
                    'host' => 'localhost',
                    'port' => 3306,
                    'username' => 'root',
                    'password' => 'secret',
                    'extra_config' => null,
                ],
                'selection_mode' => DatabaseSelectionMode::All->value,
                'pattern' => null,
                'server_name' => 'Test Server',
                'method' => 'manual',
                'triggered_by_user_id' => null,
            ],
        ]);
    }

    /**
     * Configure the job as claimed by an agent.
     */
    public function claimed(?Agent $agent = null): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent?->id ?? Agent::factory(),
            'status' => AgentJob::STATUS_CLAIMED,
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
            'status' => AgentJob::STATUS_RUNNING,
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
            'status' => AgentJob::STATUS_COMPLETED,
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
            'status' => AgentJob::STATUS_FAILED,
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
            'status' => AgentJob::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(10),
            'lease_expires_at' => now()->subMinutes(5),
            'attempts' => 1,
        ]);
    }
}
