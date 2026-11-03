<?php

namespace App\Notifications\Channels;

use App\Notifications\Concerns\PostsToEndpoint;
use Illuminate\Notifications\Notification;

class DiscordWebhookChannel
{
    use PostsToEndpoint;

    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{content: string, embeds: array<int, array<string, mixed>>} $payload */
        $payload = $notification->toDiscordWebhook($notifiable); // @phpstan-ignore method.notFound

        $config = $notifiable->channelConfig ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return;
        }

        $this->postToEndpoint($url, $payload);
    }
}
