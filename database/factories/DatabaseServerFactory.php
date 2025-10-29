<?php

namespace Database\Factories;

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
            'database_type' => fake()->randomElement(['mysql', 'postgresql', 'mariadb', 'sqlite']),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'database_name' => fake()->optional()->word(),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
