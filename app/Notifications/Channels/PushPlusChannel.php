<?php

namespace App\Notifications\Channels;

use App\Notifications\Concerns\PostsToEndpoint;
use Illuminate\Notifications\Notification;

class PushPlusChannel
{
    use PostsToEndpoint;

    public const string ENDPOINT = 'https://www.pushplus.plus/send';

    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{title: string, content: string, template: string} $payload */
        $payload = $notification->toPushPlus($notifiable); // @phpstan-ignore method.notFound

        $config = $notifiable->channelConfig ?? [];
        $token = $config['token'] ?? null;

        if (! $token) {
            return;
        }

        $payload = ['token' => $token, ...$payload, ...array_filter([
            'channel' => $config['channel'] ?? null,
            'topic' => $config['topic'] ?? null,
        ])];

        $response = $this->postToEndpoint(self::ENDPOINT, $payload);

        $code = $response->json('code');

        if ($code !== 200) {
            throw new \RuntimeException(sprintf('PushPlus rejected the message (code %s): %s', $code ?? 'none', $response->json('msg') ?? $response->body()));
        }
    }
}
