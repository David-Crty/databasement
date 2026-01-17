<?php

namespace Database\Factories;

use App\Models\Backup;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DatabaseServer>
 */
class DatabaseServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' '.fake()->randomElement(['MySQL', 'PostgreSQL', 'MariaDB']).' Server',
            'host' => fake()->randomElement(['localhost', '127.0.0.1', fake()->ipv4()]),
            'port' => fake()->randomElement([3306, 5432, 3307, 5433]),
            'database_type' => fake()->randomElement(['mysql', 'postgres']),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'database_names' => fake()->optional()->randomElements(['app', 'users', 'orders', 'products', 'analytics'], fake()->numberBetween(1, 3)),
            'description' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Configure the factory for SQLite database type.
     */
    public function sqlite(): static
    {
        return $this->state(fn () => [
            'name' => fake()->company().' SQLite Database',
            'database_type' => 'sqlite',
            'sqlite_path' => '/data/'.fake()->slug().'.sqlite',
            'host' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'database_names' => null,
        ]);
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function ($databaseServer) {
            // Create a local volume with a real temp directory for testing
            $volume = Volume::factory()->local()->create();

            Backup::create([
                'database_server_id' => $databaseServer->id,
                'volume_id' => $volume->id,
                'recurrence' => fake()->randomElement(['daily', 'weekly']),
                'retention_days' => fake()->randomElement([7, 14, 30]),
            ]);
        });
    }
}
