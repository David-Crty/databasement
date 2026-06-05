<?php

namespace Database\Factories;

use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ScheduledRestore>
 */
class ScheduledRestoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'source_server_id' => DatabaseServer::factory(),
            'source_database_name' => fake()->word(),
            'target_server_id' => DatabaseServer::factory(),
            'schema_name' => fake()->word(),
            'backup_schedule_id' => BackupSchedule::factory(),
            'options' => null,
            'enabled' => true,
            'last_executed_at' => null,
            'last_skip_reason' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
