<?php

namespace Database\Factories;

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Restore>
 */
class RestoreFactory extends Factory
{
    /**
     * A completed restore of a new snapshot onto a new server of the same type.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'backup_job_id' => fn () => BackupJob::create(['status' => 'completed'])->id,
            'snapshot_id' => Snapshot::factory(),
            'target_server_id' => fn (array $attributes) => DatabaseServer::factory()->create([
                'database_type' => Snapshot::withoutGlobalScopes()->findOrFail($attributes['snapshot_id'])->database_type,
            ])->id,
            'schema_name' => 'restored_db',
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn () => [
            'backup_job_id' => BackupJob::create(['status' => $status])->id,
        ]);
    }
}
