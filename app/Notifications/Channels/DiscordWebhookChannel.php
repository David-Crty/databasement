<?php

namespace App\Notifications\Channels;

use App\Facades\AppConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $response = Http::timeout(10)->post($url, $payload);

        if ($response->failed()) {
            Log::error('Discord webhook notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
