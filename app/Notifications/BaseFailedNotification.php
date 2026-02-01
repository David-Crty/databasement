<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;

abstract class BaseFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public \Throwable $exception
    ) {
        $this->onQueue('backups');
    }

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
        array $fields = [],
    ): FailedNotificationMessage {
        return new FailedNotificationMessage(
            title: $title,
            body: $body,
            errorMessage: $this->exception->getMessage(),
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
        return $this->getMessage()->toDiscord();
    }
}
