<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Notifications',
            'type' => 'email',
            'config' => [
                'to' => fake()->safeEmail(),
            ],
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'type' => 'email',
            'config' => ['to' => fake()->safeEmail()],
        ]);
    }

    public function slack(): static
    {
        return $this->state(fn () => [
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/'.fake()->slug()],
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn () => [
            'type' => 'discord',
            'config' => [
                'token' => 'bot-token-'.fake()->slug(),
                'channel_id' => (string) fake()->numerify('##########'),
            ],
        ]);
    }

    public function discordWebhook(): static
    {
        return $this->state(fn () => [
            'type' => 'discord_webhook',
            'config' => ['url' => 'https://discord.com/api/webhooks/'.fake()->numerify('##########').'/'.fake()->slug()],
        ]);
    }

    public function telegram(): static
    {
        return $this->state(fn () => [
            'type' => 'telegram',
            'config' => [
                'bot_token' => fake()->numerify('##########').':'.fake()->slug(),
                'chat_id' => (string) fake()->numerify('-##########'),
            ],
        ]);
    }

    public function pushover(): static
    {
        return $this->state(fn () => [
            'type' => 'pushover',
            'config' => [
                'token' => 'pushover-token-'.fake()->slug(),
                'user_key' => 'pushover-user-'.fake()->slug(),
            ],
        ]);
    }

    public function gotify(): static
    {
        return $this->state(fn () => [
            'type' => 'gotify',
            'config' => [
                'url' => 'https://gotify.example.com',
                'token' => 'gotify-token-'.fake()->slug(),
            ],
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn () => [
            'type' => 'webhook',
            'config' => [
                'url' => 'https://webhook.example.com/notify',
                'secret' => 'webhook-secret-'.fake()->slug(),
            ],
        ]);
    }
}
