<?php

namespace Database\Factories;

use App\Enums\DatabaseSelectionMode;
use App\Models\Backup;
use App\Models\DatabaseServerSshConfig;
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
            // SQLite uses this for file paths; other types leave it null so the
            // selection ends up on the Backup row instead.
            'database_names' => null,
            'description' => fake()->optional()->sentence(),
            'notification_trigger' => 'failure',
            'notification_channel_selection' => 'all',
        ];
    }

    /**
     * Configure the factory for SQLite database type. The generated file path
     * is propagated to the default Backup by {@see configure()}.
     */
    public function sqlite(): static
    {
        return $this->state(fn () => [
            'name' => fake()->company().' SQLite Database',
            'database_type' => 'sqlite',
            'host' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
        ]);
    }

    /**
     * Configure the factory for remote SQLite (via SSH/SFTP).
     *
     * Note: Uses afterCreating() hook, so only works with create(), not make().
     *
     * @param  array<string, mixed>  $overrides
     */
    public function sqliteRemote(array $overrides = []): static
    {
        return $this->sqlite()->afterCreating(function ($databaseServer) use ($overrides) {
            $sshConfig = DatabaseServerSshConfig::factory()->create(array_merge([
                'host' => 'remote.example.com',
                'port' => 22,
                'username' => 'deploy',
                'auth_type' => 'password',
                'password' => 'ssh_password',
            ], $overrides));

            $databaseServer->update(['ssh_config_id' => $sshConfig->id]);
        });
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
        ]);
    }

    /**
     * Configure the factory for pattern-based database selection on the first
     * Backup row attached to this server.
     */
    public function pattern(string $pattern = '^prod_'): static
    {
        return $this->afterCreating(function ($databaseServer) use ($pattern) {
            $backup = $databaseServer->backups()->first();
            if ($backup !== null) {
                $backup->update([
                    'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
                    'database_names' => null,
                    'database_include_pattern' => $pattern,
                ]);
            }
        });
    }

    /**
     * Configure the factory for MongoDB database type.
     */
    public function mongodb(): static
    {
        return $this->state(fn () => [
            'name' => fake()->company().' MongoDB Server',
            'database_type' => 'mongodb',
            'host' => fake()->randomElement(['localhost', '127.0.0.1']),
            'port' => 27017,
            'username' => 'root',
            'password' => 'root',
            'database_names' => null,
            'extra_config' => ['auth_source' => 'admin'],
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
     * Strip the default Backup created by {@see configure()} — useful for tests
     * that need to build backups explicitly via `Backup::factory()`.
     */
    public function withoutBackups(): static
    {
        return $this->afterCreating(function ($databaseServer) {
            $databaseServer->backups()->delete();
        });
    }

    /**
     * Attach N backup configurations (each on a distinct schedule) to this
     * server. {@see configure()} creates the first backup; this hook tops it
     * up with (N - 1) more.
     */
    public function withBackups(int $count = 2): static
    {
        return $this->afterCreating(function ($databaseServer) use ($count) {
            for ($i = 1; $i < $count; $i++) {
                Backup::factory()->for($databaseServer)->create();
            }
        });
    }

    /**
     * Configure the model factory — by default every server gets one
     * "sensible" Backup attached so existing tests keep working. If legacy
     * backup-related state (database_names for non-SQLite, pattern, etc.)
     * was set on the server via ->create([...]), propagate it to the backup
     * and clear it from the server.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function ($databaseServer) {
                // Strip legacy backup-related attributes off the server model
                // before it hits the DB — they live on Backup now — and stash
                // them on a transient property for the afterCreating hook.
                foreach (['database_selection_mode', 'database_include_pattern', 'database_names'] as $field) {
                    if (array_key_exists($field, $databaseServer->getAttributes())) {
                        $databaseServer->pendingBackupState[$field] = $databaseServer->getAttributes()[$field];
                        unset($databaseServer->{$field});
                    }
                }
            })
            ->afterCreating(function ($databaseServer) {
                $legacy = $databaseServer->pendingBackupState;
                $databaseServer->pendingBackupState = [];

                $backupState = [];
                $isSqlite = $databaseServer->database_type->value === 'sqlite';

                if ($isSqlite) {
                    $backupState['database_selection_mode'] = DatabaseSelectionMode::Selected->value;
                    $backupState['database_names'] = $legacy['database_names']
                        ?? ['/data/'.fake()->slug().'.sqlite'];
                    unset($legacy['database_names']);
                } elseif (! empty($legacy['database_names'])) {
                    $backupState['database_selection_mode'] = DatabaseSelectionMode::Selected->value;
                    $backupState['database_names'] = $legacy['database_names'];
                    unset($legacy['database_names']);
                }

                // Merge any remaining legacy state (e.g. include_pattern, mode override)
                foreach ($legacy as $key => $value) {
                    $backupState[$key] = $value;
                }

                Backup::factory()->for($databaseServer)->state($backupState)->create();
            });
    }
}
