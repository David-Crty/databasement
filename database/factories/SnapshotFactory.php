<?php

namespace Database\Factories;

use App\Enums\SnapshotFileStatus;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
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
        return $this
            ->afterMaking(function (Snapshot $snapshot) {
                // If no database_server_id, create one (with its default backup)
                if (! $snapshot->database_server_id) {
                    $server = DatabaseServer::factory()->create();
                    $backup = $server->backups()->oldest('id')->firstOrFail();
                    $snapshot->database_server_id = $server->id;
                    $snapshot->backup_id = $backup->id;
                    $snapshot->database_type = $server->database_type;
                }

                // If no backup_job_id, create one
                if (! $snapshot->backup_job_id) {
                    $job = BackupJob::create(['status' => 'completed']);
                    $snapshot->backup_job_id = $job->id;
                }
            })
            ->afterCreating(function (Snapshot $snapshot) {
                // One copy row per target volume of the backup config, mirroring
                // BackupJobFactory::createSnapshot. onVolumes() replaces these.
                if ($snapshot->files()->exists()) {
                    return;
                }

                self::createFileRows($snapshot, $snapshot->backup?->volumes ?? collect());
            });
    }

    /**
     * Create the per-volume copy rows for a snapshot. A snapshot that already
     * has an archive filename counts as uploaded to each of its volumes.
     *
     * @param  iterable<int, Volume>  $volumes
     */
    private static function createFileRows(Snapshot $snapshot, iterable $volumes): void
    {
        $uploaded = (string) ($snapshot->filename ?? '') !== '';

        foreach ($volumes as $volume) {
            $snapshot->files()->create([
                'volume_id' => $volume->id,
                'status' => $uploaded ? SnapshotFileStatus::Completed : SnapshotFileStatus::Pending,
                'file_exists' => true,
            ]);
        }

        $snapshot->unsetRelation('files');
    }

    /**
     * Store the snapshot's copies on these specific volumes instead of the
     * backup config's volumes.
     */
    public function onVolumes(Volume ...$volumes): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) use ($volumes) {
            $snapshot->files()->delete();
            self::createFileRows($snapshot, $volumes);
        });
    }

    /**
     * Set the snapshot to be for a specific server (using its first backup).
     */
    public function forServer(DatabaseServer $server): static
    {
        $backup = $server->backups()->oldest('id')->firstOrFail();
        $databaseName = $backup->database_names[0] ?? 'testdb';

        return $this->state(fn () => [
            'database_server_id' => $server->id,
            'backup_id' => $backup->id,
            'database_type' => $server->database_type,
            'database_name' => $databaseName,
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
     * Mark the snapshot's job as completed.
     */
    public function completed(): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) {
            $snapshot->job->update(['status' => 'completed']);
        });
    }

    /**
     * Mark the snapshot's job as failed.
     */
    public function failed(): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) {
            $snapshot->job->update(['status' => 'failed']);
        });
    }

    /**
     * Set the snapshot file as missing (on every volume copy).
     */
    public function fileMissing(): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) {
            $snapshot->files()->update(['file_exists' => false, 'file_verified_at' => now()]);
            $snapshot->unsetRelation('files');
        });
    }

    /**
     * Set the snapshot file as verified (exists).
     */
    public function fileVerified(): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) {
            $snapshot->files()->update(['file_exists' => true, 'file_verified_at' => now()]);
            $snapshot->unsetRelation('files');
        });
    }

    /**
     * Create a snapshot with a real file on each of its (local) volumes.
     */
    public function withFile(?string $content = null): static
    {
        return $this->afterCreating(function (Snapshot $snapshot) use ($content) {
            $size = null;

            foreach ($snapshot->files()->with('volume')->get() as $file) {
                $volumePath = $file->volume->config['path'] ?? sys_get_temp_dir();

                $filePath = $volumePath.'/'.$snapshot->filename;
                $dir = dirname($filePath);

                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                file_put_contents($filePath, $content ?? 'test backup content');

                $size = filesize($filePath);
            }

            if ($size !== null) {
                $snapshot->update(['file_size' => $size]);
            }
        });
    }
}
