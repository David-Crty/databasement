<?php

namespace Database\Factories;

use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserServerAccess>
 */
class UserServerAccessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'database_server_id' => DatabaseServer::factory(),
            'allowed_databases' => null,
            'can_download' => true,
            'can_backup' => false,
            'can_restore' => false,
        ];
    }

    public function withDatabases(string ...$databases): static
    {
        return $this->state(['allowed_databases' => array_values($databases)]);
    }

    public function canBackup(): static
    {
        return $this->state(['can_backup' => true]);
    }

    public function canRestore(): static
    {
        return $this->state(['can_restore' => true]);
    }

    public function cannotDownload(): static
    {
        return $this->state(['can_download' => false]);
    }
}
