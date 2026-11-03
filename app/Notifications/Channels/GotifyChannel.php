<?php

namespace App\Notifications\Channels;

use App\Notifications\Concerns\PostsToEndpoint;
use Illuminate\Notifications\Notification;

class GotifyChannel
{
    use PostsToEndpoint;

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

        $this->postToEndpoint(rtrim($url, '/').'/message', $payload, ['X-Gotify-Key' => $token]);
    }
}
