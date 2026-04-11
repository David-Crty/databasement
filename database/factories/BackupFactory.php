<?php

namespace Database\Factories;

use App\Enums\DatabaseSelectionMode;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Backup>
 */
class BackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'database_server_id' => DatabaseServer::factory()->withoutBackups(),
            'volume_id' => fn () => Volume::factory()->local()->create()->id,
            'path' => null,
            'backup_schedule_id' => fn () => BackupSchedule::firstOrCreate(
                ['name' => 'Daily'],
                ['expression' => '0 2 * * *'],
            )->id,
            'retention_policy' => Backup::RETENTION_DAYS,
            'retention_days' => fake()->randomElement([7, 14, 30]),
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
            'database_selection_mode' => DatabaseSelectionMode::All->value,
            'database_names' => null,
            'database_include_pattern' => null,
        ];
    }

    /**
     * State: back up a specific list of databases (client-server types only).
     *
     * @param  array<int, string>  $databases
     */
    public function selected(array $databases = ['app']): static
    {
        return $this->state(fn () => [
            'database_selection_mode' => DatabaseSelectionMode::Selected->value,
            'database_names' => $databases,
            'database_include_pattern' => null,
        ]);
    }

    /**
     * State: back up databases matching a regex pattern.
     */
    public function pattern(string $pattern = '^prod_'): static
    {
        return $this->state(fn () => [
            'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
            'database_names' => null,
            'database_include_pattern' => $pattern,
        ]);
    }

    /**
     * State: GFS retention policy with default tiers.
     */
    public function gfs(int $daily = 7, int $weekly = 4, int $monthly = 12): static
    {
        return $this->state(fn () => [
            'retention_policy' => Backup::RETENTION_GFS,
            'retention_days' => null,
            'gfs_keep_daily' => $daily,
            'gfs_keep_weekly' => $weekly,
            'gfs_keep_monthly' => $monthly,
        ]);
    }

    /**
     * State: forever retention.
     */
    public function forever(): static
    {
        return $this->state(fn () => [
            'retention_policy' => Backup::RETENTION_FOREVER,
            'retention_days' => null,
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
        ]);
    }

    /**
     * State: attach to an existing BackupSchedule (by name or ID).
     */
    public function onSchedule(string $name): static
    {
        return $this->state(fn () => [
            'backup_schedule_id' => fn () => BackupSchedule::firstOrCreate(
                ['name' => $name],
                ['expression' => '0 2 * * *'],
            )->id,
        ]);
    }
}
