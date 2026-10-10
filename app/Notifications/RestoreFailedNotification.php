<?php

namespace App\Notifications;

use App\Models\Restore;
use App\Notifications\Concerns\DescribesRestore;

class RestoreFailedNotification extends BaseFailedNotification
{
    use DescribesRestore;

    public function __construct(
        public Restore $restore,
        \Throwable $exception
    ) {
        parent::__construct($exception);
    }

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '🚨 '.__('Restore Failed: :server', ['server' => $this->targetServerName()]),
            body: __('A restore job has failed and requires your attention.'),
            actionUrl: $this->restoreUrl(),
            fields: $this->restoreFields(),
        );
    }
}
