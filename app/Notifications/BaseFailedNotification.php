<?php

namespace App\Notifications;

use App\Facades\AppConfig;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;

abstract class BaseFailedNotification extends Notification
{
    public function __construct(
        public \Throwable $exception
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $routes = $notifiable->routes ?? [];

        return array_keys(array_filter($routes));
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
}
