<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Channels\GotifyChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\FailedNotificationMessage;
use App\Notifications\SuccessNotificationMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordChannel;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

trait HasChannelRouting
{
    /**
     * Map route keys to their Laravel notification channel identifiers.
     *
     * @var array<string, string>
     */
    private const array CHANNEL_MAP = [
        'mail' => 'mail',
        'slack' => 'slack',
        'discord' => DiscordChannel::class,
        'telegram' => TelegramChannel::class,
        'pushover' => PushoverChannel::class,
        'gotify' => GotifyChannel::class,
        'discord_webhook' => DiscordWebhookChannel::class,
        'webhook' => WebhookChannel::class,
    ];

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $routes = $notifiable->routes ?? [];

        return array_map(
            fn (string $key) => self::CHANNEL_MAP[$key] ?? $key,
            array_keys(array_filter($routes)),
        );
    }

    /**
     * Get the notification message.
     */
    abstract public function getMessage(): FailedNotificationMessage|SuccessNotificationMessage;

    public function toMail(object $notifiable): MailMessage
    {
        return $this->getMessage()->toMail();
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return $this->getMessage()->toSlack();
    }

    public function toDiscord(object $notifiable): DiscordMessage
    {
        return $this->getMessage()->toDiscord();
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $chatId = (string) ($notifiable->routes['telegram'] ?? '');

        return $this->getMessage()->toTelegram($chatId);
    }

    public function toPushover(object $notifiable): PushoverMessage
    {
        return $this->getMessage()->toPushover();
    }

    /**
     * @return array{content: string, embeds: array<int, array<string, mixed>>}
     */
    public function toDiscordWebhook(object $notifiable): array
    {
        return $this->getMessage()->toDiscordWebhook();
    }

    /**
     * @return array{title: string, message: string, priority: int}
     */
    public function toGotify(object $notifiable): array
    {
        return $this->getMessage()->toGotify();
    }

    /**
     * @return array{event: string, title: string, body: string, fields: array<string, string>, error?: string, action_url: string, timestamp: string}
     */
    public function toWebhook(object $notifiable): array
    {
        return $this->getMessage()->toWebhook(class_basename($this));
    }
}
