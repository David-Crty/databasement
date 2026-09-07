<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SnapshotObjectFile>
 */
class SnapshotObjectFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => fake()->filePath(),
            'size' => fake()->numberBetween(1, 1024 * 1024 * 50),
            'mtime' => fake()->dateTimeBetween('-30 days'),
            'checksum' => fake()->sha256(),
            'tombstone' => false,
        ];
    }

    /**
     * Mark the row as a tombstone for a source-deleted object.
     */
    public function tombstone(): static
    {
        return $this->state(fn () => [
            'size' => null,
            'mtime' => null,
            'checksum' => null,
            'tombstone' => true,
        ]);
    }
}
