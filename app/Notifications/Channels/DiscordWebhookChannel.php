<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class DiscordWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{content: string, embeds: array<int, array<string, mixed>>} $payload */
        $payload = $notification->toDiscordWebhook($notifiable); // @phpstan-ignore method.notFound

        $config = $notifiable->channelConfig ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return;
        }

        Http::timeout(10)->post($url, $payload)->throw();
    }
}
