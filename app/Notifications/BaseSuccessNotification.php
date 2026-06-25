<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\HasChannelRouting;
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
        string $actionText,
        string $actionUrl,
        string $footerText,
        array $fields = [],
    ): NotificationMessage {
        return new NotificationMessage(
            type: NotificationType::Success,
            title: $title,
            body: $body,
            actionText: $actionText,
            actionUrl: $actionUrl,
            footerText: $footerText,
            fields: $fields,
        );
    }
}
