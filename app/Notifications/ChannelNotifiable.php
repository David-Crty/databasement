<?php

namespace App\Notifications;

use Illuminate\Notifications\Notifiable;

class ChannelNotifiable
{
    use Notifiable;

    /**
     * @param  array<string, string>  $routes
     * @param  array<string, mixed>  $channelConfig
     */
    public function __construct(
        public array $routes,
        public array $channelConfig = [],
    ) {}

    /**
     * Route notifications for all channels.
     */
    public function routeNotificationFor(string $driver, mixed $notification = null): mixed
    {
        return $this->routes[$driver] ?? null;
    }

    /**
     * Get the value of the notifiable's primary key.
     * Required by Notification::send() for keying notifications.
     */
    public function getKey(): string
    {
        return md5((string) json_encode($this->routes));
    }
}
