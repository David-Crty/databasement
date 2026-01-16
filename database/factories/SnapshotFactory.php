<?php

namespace Database\Factories;

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Snapshot>
 */
class SnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => fake()->slug().'.sql.gz',
            'file_size' => fake()->numberBetween(1024, 1024 * 1024 * 100),
            'checksum' => fake()->sha256(),
            'started_at' => now(),
            'database_name' => fake()->randomElement(['app', 'users', 'orders', 'products']),
            'compression_type' => 'gzip',
            'method' => fake()->randomElement(['manual', 'scheduled']),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Snapshot $snapshot) {
            // If no database_server_id, create one
            if (! $snapshot->database_server_id) {
                $server = DatabaseServer::factory()->create();
                $snapshot->database_server_id = $server->id;
                $snapshot->backup_id = $server->backup->id;
                $snapshot->volume_id = $server->backup->volume_id;
                $snapshot->database_type = $server->database_type;
            }

            // If no backup_job_id, create one
            if (! $snapshot->backup_job_id) {
                $job = BackupJob::create(['status' => 'completed']);
                $snapshot->backup_job_id = $job->id;
            }
        });
    }

    /**
     * Set the snapshot to be for a specific server.
     */
    public function forServer(DatabaseServer $server): static
    {
        return $this->state(fn () => [
            'database_server_id' => $server->id,
            'backup_id' => $server->backup->id,
            'volume_id' => $server->backup->volume_id,
            'database_type' => $server->database_type,
            'database_name' => $server->database_names[0] ?? 'testdb',
        ]);
    }

    /**
     * Set the snapshot method to manual.
     */
    public function manual(): static
    {
        return $this->state(fn () => [
            'method' => 'manual',
        ]);
    }

    /**
     * Set the snapshot method to scheduled.
     */
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'method' => 'scheduled',
        ]);
    }

    /**
     * Create a snapshot with a real file in the volume.
     */
    public function withFile(?string $content = null): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) use ($content) {
            $volume = $snapshot->volume;
            $volumePath = $volume->config['path'] ?? sys_get_temp_dir();

            if (! is_dir($volumePath)) {
                mkdir($volumePath, 0755, true);
            }

            $filePath = $volumePath.'/'.$snapshot->filename;
            file_put_contents($filePath, $content ?? 'test backup content');

            $snapshot->update(['file_size' => filesize($filePath)]);
        });
    }
}
