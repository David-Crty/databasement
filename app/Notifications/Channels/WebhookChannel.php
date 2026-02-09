<?php

namespace App\Notifications\Channels;

use App\Facades\AppConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $headers = [
            'X-Webhook-Event' => class_basename($notification),
        ];

        $secret = AppConfig::get('notifications.webhook.secret');

        if ($secret) {
            $headers['X-Webhook-Token'] = $secret;
        }

        $response = Http::timeout(10)->withHeaders($headers)->post($url, $payload);

        if ($response->failed()) {
            Log::error('Webhook notification failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
