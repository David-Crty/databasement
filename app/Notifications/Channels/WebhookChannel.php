<?php

namespace App\Notifications\Channels;

use App\Facades\AppConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $notification->toWebhook($notifiable); // @phpstan-ignore method.notFound

        $url = AppConfig::get('notifications.webhook.url');

        if (! $url) {
            return;
        }

        $secret = AppConfig::get('notifications.webhook.secret');
        $headers = $secret ? ['X-Webhook-Token' => $secret] : [];

        Http::timeout(10)->withHeaders($headers)->post($url, $payload)->throw();
    }
}
