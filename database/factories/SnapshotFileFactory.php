<?php

namespace Database\Factories;

use App\Enums\SnapshotFileStatus;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SnapshotFile>
 */
class SnapshotFileFactory extends Factory
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
            'volume_id' => fn () => Volume::factory()->local()->create()->id,
            'status' => SnapshotFileStatus::Completed,
            'file_exists' => true,
            'file_verified_at' => null,
        ];
    }

    /**
     * Mark the copy's upload as failed.
     */
    public function failed(?string $error = null): static
    {
        return $this->state(fn () => [
            'status' => SnapshotFileStatus::Failed,
            'error' => $error ?? 'Upload failed',
            'file_exists' => false,
            'file_verified_at' => null,
        ]);
    }

    /**
     * Mark the copy's file as missing from its volume.
     */
    public function fileMissing(): static
    {
        return $this->state(fn () => [
            'file_exists' => false,
            'file_verified_at' => now(),
        ]);
    }
}
