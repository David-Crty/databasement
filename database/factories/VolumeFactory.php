<?php

namespace Database\Factories;

use App\Models\Organization;
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
            'organization_id' => fn () => Organization::first()?->id ?? Organization::factory()->default(),
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
     * Indicate that the volume is an SMB / Samba type.
     */
    public function smb(): static
    {
        return $this->state(fn () => [
            'type' => 'smb',
            'config' => [
                'host' => 'fileserver.example.com',
                'share' => 'backups',
                'username' => 'backup-user',
                'password' => 'test-password',
                'domain' => 'WORKGROUP',
                'root' => '/databasement',
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

    /**
     * Indicate that the volume is an Azure Blob Storage type.
     */
    public function azure(): static
    {
        return $this->state(fn () => [
            'type' => 'azure',
            'config' => [
                'account_name' => 'teststorageaccount',
                'account_key' => 'test-account-key-'.fake()->slug(),
                'container' => 'backup-'.fake()->slug(),
                'prefix' => fake()->optional()->slug(),
                'endpoint_suffix' => 'core.windows.net',
                'endpoint' => '',
            ],
        ]);
    }
}
