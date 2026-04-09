<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $notification->toWebhook($notifiable); // @phpstan-ignore method.notFound

        $config = $notifiable->channelConfig ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return;
        }

        $secret = $config['secret'] ?? null;
        $headers = $secret ? ['X-Webhook-Token' => $secret] : [];

        Http::timeout(10)->withHeaders($headers)->post($url, $payload)->throw();
    }
}
