<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BackupSchedule>
 */
class BackupScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'expression' => fake()->randomElement([
                '0 2 * * *',
                '0 3 * * 0',
                '0 */3 * * *',
                '0 0 * * *',
                '30 1 * * *',
            ]),
        ];
    }

    public function daily(): static
    {
        return $this->state(fn () => [
            'name' => 'Daily',
            'expression' => '0 2 * * *',
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn () => [
            'name' => 'Weekly',
            'expression' => '0 3 * * 0',
        ]);
    }
}
