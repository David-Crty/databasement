<?php

namespace Database\Factories;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServerSshConfig;
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
     * Configure the factory for Redis database type.
     */
    public function redis(): static
    {
        return $this->state(fn () => [
            'name' => fake()->company().' Redis Server',
            'database_type' => 'redis',
            'host' => fake()->randomElement(['localhost', '127.0.0.1']),
            'port' => 6379,
            'username' => '',
            'password' => '',
            'database_names' => null,
            'backup_all_databases' => true,
        ]);
    }

    /**
     * Configure the factory with SSH tunnel using password authentication.
     *
     * Note: Uses afterCreating() hook, so only works with create(), not make().
     * For make(), manually create the SSH config and use setRelation().
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withSshTunnel(array $overrides = []): static
    {
        return $this->afterCreating(function ($databaseServer) use ($overrides) {
            $sshConfig = DatabaseServerSshConfig::factory()->create(array_merge([
                'host' => 'bastion.example.com',
                'port' => 22,
                'username' => 'tunnel_user',
                'auth_type' => 'password',
                'password' => 'ssh_password',
            ], $overrides));

            $databaseServer->update(['ssh_config_id' => $sshConfig->id]);
        });
    }

    /**
     * Configure the factory with SSH tunnel using key authentication.
     *
     * Note: Uses afterCreating() hook, so only works with create(), not make().
     * For make(), manually create the SSH config and use setRelation().
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withSshTunnelKey(array $overrides = []): static
    {
        return $this->afterCreating(function ($databaseServer) use ($overrides) {
            $sshConfig = DatabaseServerSshConfig::factory()->withKeyAuth()->create(array_merge([
                'host' => 'bastion.example.com',
                'port' => 22,
                'username' => 'tunnel_user',
            ], $overrides));

            $databaseServer->update(['ssh_config_id' => $sshConfig->id]);
        });
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function ($databaseServer) {
            // Create a local volume with a real temp directory for testing
            $volume = Volume::factory()->local()->create();
            $schedule = BackupSchedule::firstOrCreate(
                ['name' => 'Daily'],
                ['expression' => '0 2 * * *'],
            );

            Backup::create([
                'database_server_id' => $databaseServer->id,
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_days' => fake()->randomElement([7, 14, 30]),
            ]);
        });
    }
}
