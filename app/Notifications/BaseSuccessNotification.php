<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\HasChannelRouting;
use App\Support\Formatters;
use Illuminate\Notifications\Notification;

abstract class BaseSuccessNotification extends Notification
{
    use HasChannelRouting;

    abstract public function getMessage(): NotificationMessage;

    /**
     * Create a success notification message.
     *
     * @param  array<string, string>  $fields
     */
    protected function message(
        string $title,
        string $body,
        string $actionUrl,
        array $fields = [],
        ?string $actionText = null,
    ): NotificationMessage {
        return new NotificationMessage(
            type: NotificationType::Success,
            title: $title,
            body: $body,
            actionText: $actionText ?? '🔗 '.__('View Job Details'),
            actionUrl: $actionUrl,
            footerText: '🕐 '.Formatters::humanDate(now()),
            fields: $fields,
        );
    }
}
