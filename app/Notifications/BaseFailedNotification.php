<?php

namespace App\Notifications;

use App\Facades\AppConfig;
use App\Notifications\Channels\GotifyChannel;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

abstract class BaseFailedNotification extends Notification
{
    /**
     * Map route keys to their Laravel notification channel identifiers.
     *
     * @var array<string, string>
     */
    private const array CHANNEL_MAP = [
        'mail' => 'mail',
        'slack' => 'slack',
        'discord' => 'discord',
        'telegram' => TelegramChannel::class,
        'pushover' => PushoverChannel::class,
        'gotify' => GotifyChannel::class,
        'webhook' => WebhookChannel::class,
    ];

    public function __construct(
        public \Throwable $exception
    ) {}

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
    abstract public function getMessage(): FailedNotificationMessage;

    /**
     * Create a failed notification message.
     *
     * @param  array<string, string>  $fields
     */
    protected function message(
        string $title,
        string $body,
        string $actionText,
        string $actionUrl,
        string $footerText,
        string $errorLabel,
        array $fields = [],
    ): FailedNotificationMessage {
        return new FailedNotificationMessage(
            title: $title,
            body: $body,
            errorMessage: $this->exception->getMessage(),
            errorLabel: $errorLabel,
            actionText: $actionText,
            actionUrl: $actionUrl,
            footerText: $footerText,
            fields: $fields,
        );
    }

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
        // Ensure the Discord token is fresh from AppConfig at send time
        $token = AppConfig::get('notifications.discord.token');
        if ($token) {
            config(['services.discord.token' => $token]);
        }

        return $this->getMessage()->toDiscord();
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        // Ensure the Telegram bot token is fresh from AppConfig at send time
        $token = AppConfig::get('notifications.telegram.bot_token');
        if ($token) {
            config(['services.telegram-bot-api.token' => $token]);
        }

        $chatId = (string) ($notifiable->routes['telegram'] ?? '');

        return $this->getMessage()->toTelegram($chatId);
    }

    public function toPushover(object $notifiable): PushoverMessage
    {
        // Ensure the Pushover app token is fresh from AppConfig at send time
        $token = AppConfig::get('notifications.pushover.token');
        if ($token) {
            config(['services.pushover.token' => $token]);
        }

        return $this->getMessage()->toPushover();
    }

    /**
     * @return array{title: string, message: string, priority: int}
     */
    public function toGotify(object $notifiable): array
    {
        return $this->getMessage()->toGotify();
    }

    /**
     * @return array{event: string, title: string, body: string, fields: array<string, string>, error: string, action_url: string, timestamp: string}
     */
    public function toWebhook(object $notifiable): array
    {
        return $this->getMessage()->toWebhook();
    }
}
