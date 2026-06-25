<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\HasChannelRouting;
use Illuminate\Notifications\Notification;

abstract class BaseFailedNotification extends Notification
{
    use HasChannelRouting;

    public function __construct(
        public \Throwable $exception
    ) {}

    abstract public function getMessage(): NotificationMessage;

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
    ): NotificationMessage {
        return new NotificationMessage(
            type: NotificationType::Failure,
            title: $title,
            body: $body,
            actionText: $actionText,
            actionUrl: $actionUrl,
            footerText: $footerText,
            fields: $fields,
            errorMessage: $this->exception->getMessage(),
            errorLabel: $errorLabel,
        );
    }
}
