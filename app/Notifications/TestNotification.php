<?php

namespace App\Notifications;

class TestNotification extends BaseSuccessNotification
{
    public function __construct(
        public string $channelName
    ) {}

    public function getMessage(): SuccessNotificationMessage
    {
        return $this->message(
            title: '🔔 Test Notification',
            body: "This is a test notification from Databasement for channel: {$this->channelName}.",
            actionText: '🔗 Open Databasement',
            actionUrl: route('configuration.index'),
            footerText: '🕐 '.now()->toDateTimeString(),
        );
    }
}
