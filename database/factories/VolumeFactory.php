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
        return [
            'name' => fake()->unique()->words(2, true).' Volume',
            'type' => 'local',
            'config' => [
                'path' => $this->createTempDirectory(),
            ],
        ];
    }

    /**
     * Indicate that the volume is a local type.
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'local',
            'config' => [
                'path' => $this->createTempDirectory(),
            ],
        ]);
    }

    /**
     * Create a temporary directory that actually exists on the filesystem.
     * This is used during testing to ensure file operations work correctly.
     */
    private function createTempDirectory(): string
    {
        $path = sys_get_temp_dir().'/volume-test-'.uniqid('', true);
        @mkdir($path, 0755, true);

        return $path;
    }

    /**
     * Indicate that the volume is an S3 type.
     */
    public function s3(): static
    {
        return $this->state(fn () => [
            'type' => 's3',
            'config' => [
                'bucket' => 'backup-'.fake()->slug(),
                'prefix' => fake()->optional()->slug(),
                'region' => 'us-east-1',
                'access_key_id' => 'test-key-'.fake()->slug(),
                'secret_access_key' => 'test-secret-'.fake()->slug(),
                'custom_endpoint' => '',
                'public_endpoint' => '',
                'use_path_style_endpoint' => false,
                'custom_role_arn' => '',
                'role_session_name' => '',
                'sts_endpoint' => '',
            ],
        ]);
    }

    /**
     * Indicate that the volume is an SFTP type.
     */
    public function sftp(): static
    {
        return $this->state(fn () => [
            'type' => 'sftp',
            'config' => [
                'host' => 'sftp.example.com',
                'port' => 22,
                'username' => 'backup-user',
                'password' => 'test-password',
                'root' => '/backups',
                'timeout' => 10,
            ],
        ]);
    }

    /**
     * Indicate that the volume is an FTP type.
     */
    public function ftp(): static
    {
        return $this->state(fn () => [
            'type' => 'ftp',
            'config' => [
                'host' => 'ftp.example.com',
                'port' => 21,
                'username' => 'backup-user',
                'password' => 'test-password',
                'root' => '/backups',
                'ssl' => false,
                'passive' => true,
                'timeout' => 90,
            ],
        ]);
    }
}
