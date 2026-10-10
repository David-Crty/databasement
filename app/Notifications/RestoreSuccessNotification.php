<?php

namespace App\Notifications;

use App\Models\Restore;
use App\Notifications\Concerns\DescribesRestore;

class RestoreSuccessNotification extends BaseSuccessNotification
{
    use DescribesRestore;

    public function __construct(
        public Restore $restore
    ) {}

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '✅ '.__('Restore Succeeded: :server', ['server' => $this->targetServerName()]),
            body: __('A restore job completed successfully.'),
            actionUrl: $this->restoreUrl(),
            fields: $this->restoreFields(),
        );
    }
}
