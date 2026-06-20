<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Agent',
            'organization_id' => fn () => Organization::first()?->id ?? Organization::factory()->default(),
        ];
    }

    /**
     * Configure the agent as online (recent heartbeat).
     */
    public function online(): static
    {
        return $this->state(fn () => [
            'last_heartbeat_at' => now()->subSeconds(30),
        ]);
    }

    /**
     * Configure the agent as offline (no recent heartbeat).
     */
    public function offline(): static
    {
        return $this->state(fn () => [
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);
    }
}
