<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\HasChannelRouting;
use App\Support\Formatters;
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
        string $actionUrl,
        array $fields = [],
        ?string $actionText = null,
        ?string $errorLabel = null,
    ): NotificationMessage {
        return new NotificationMessage(
            type: NotificationType::Failure,
            title: $title,
            body: $body,
            actionText: $actionText ?? '🔗 '.__('View Job Details'),
            actionUrl: $actionUrl,
            footerText: '🕐 '.Formatters::humanDate(now()),
            fields: $fields,
            errorMessage: $this->exception->getMessage(),
            errorLabel: $errorLabel ?? '❌ '.__('Error Details'),
        );
    }
}
