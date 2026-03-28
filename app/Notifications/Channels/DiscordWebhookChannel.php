<?php

namespace App\Notifications\Channels;

use App\Facades\AppConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class DiscordWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{content: string, embeds: array<int, array<string, mixed>>} $payload */
        $payload = $notification->toDiscordWebhook($notifiable); // @phpstan-ignore method.notFound

        $url = AppConfig::get('notifications.discord_webhook.url');

        if (! $url) {
            return;
        }

        Http::timeout(10)->post($url, $payload)->throw();
    }
}
