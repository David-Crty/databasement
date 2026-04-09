<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class GotifyChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{title: string, message: string, priority: int} $payload */
        $payload = $notification->toGotify($notifiable); // @phpstan-ignore method.notFound

        $config = $notifiable->channelConfig ?? [];
        $url = $config['url'] ?? null;
        $token = $config['token'] ?? null;

        if (! $url || ! $token) {
            return;
        }

        Http::timeout(10)
            ->withHeader('X-Gotify-Key', $token)
            ->post(rtrim($url, '/').'/message', $payload)
            ->throw();
    }
}
