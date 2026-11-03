<?php

namespace App\Notifications\Channels;

use App\Notifications\Concerns\PostsToEndpoint;
use Illuminate\Notifications\Notification;

class WebhookChannel
{
    use PostsToEndpoint;

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

        $this->postToEndpoint($url, $payload, $headers);
    }
}
