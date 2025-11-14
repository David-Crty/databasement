<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Volume>
 */
class VolumeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['local', 's3']);

        return [
            'name' => fake()->unique()->words(2, true).' Volume',
            'type' => $type,
            'config' => $this->buildConfig($type),
        ];
    }

    /**
     * Build configuration based on volume type.
     */
    private function buildConfig(string $type): array
    {
        return match ($type) {
            's3' => [
                'bucket' => 'backup-'.fake()->slug(),
                'prefix' => fake()->optional()->slug(),
            ],
            'local' => [
                'path' => '/var/backups/'.fake()->slug(),
            ],
            default => throw new \InvalidArgumentException("Invalid volume type: {$type}"),
        };
    }

    /**
     * Indicate that the volume is a local type.
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'local',
            'config' => [
                'path' => '/var/backups/'.fake()->slug(),
            ],
        ]);
    }

    /**
     * Indicate that the volume is an S3 type.
     */
    public function s3(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 's3',
            'config' => [
                'bucket' => 'backup-'.fake()->slug(),
                'prefix' => fake()->optional()->slug(),
            ],
        ]);
    }
}
