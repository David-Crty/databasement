<?php

namespace App\Notifications\Channels;

use App\Facades\AppConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GotifyChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{title: string, message: string, priority: int} $payload */
        $payload = $notification->toGotify($notifiable); // @phpstan-ignore method.notFound

        $url = AppConfig::get('notifications.gotify.url');
        $token = AppConfig::get('notifications.gotify.token');

        if (! $url || ! $token) {
            return;
        }

        $response = Http::timeout(10)
            ->withHeader('X-Gotify-Key', $token)
            ->post(rtrim($url, '/').'/message', $payload);

        if ($response->failed()) {
            Log::error('Gotify notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
