<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DatabaseServerSshConfig>
 */
class DatabaseServerSshConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host' => 'bastion.example.com',
            'port' => 22,
            'username' => 'tunnel_user',
            'auth_type' => 'password',
            'password' => 'ssh_password',
            'private_key' => null,
            'key_passphrase' => null,
        ];
    }

    /**
     * Configure the factory for key-based authentication.
     */
    public function withKeyAuth(): static
    {
        // Use a placeholder string to avoid triggering secret scanners
        $sampleKey = 'FAKE_SSH_PRIVATE_KEY_FOR_TESTS_ONLY';

        return $this->state(fn () => [
            'auth_type' => 'key',
            'password' => null,
            'private_key' => $sampleKey,
            'key_passphrase' => null,
        ]);
    }

    /**
     * Configure the factory for key-based authentication with passphrase.
     */
    public function withKeyAuthAndPassphrase(): static
    {
        $sampleKey = 'FAKE_SSH_PRIVATE_KEY_FOR_TESTS_ONLY';

        return $this->state(fn () => [
            'auth_type' => 'key',
            'password' => null,
            'private_key' => $sampleKey,
            'key_passphrase' => 'test_passphrase',
        ]);
    }
}
