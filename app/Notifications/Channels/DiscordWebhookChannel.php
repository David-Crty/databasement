<?php

namespace App\Notifications\Channels;

use Illuminate\Http\Client\RequestException;
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

        $response = Http::timeout(10)->withoutRedirecting()->post($url, $payload);

        throw_if($response->redirect(), new RequestException($response));

        $response->throw();
    }
}
