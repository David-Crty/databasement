<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Failure Notifications
    |--------------------------------------------------------------------------
    |
    | Configure notifications for backup and restore job failures.
    | Notifications can be sent via email and/or Slack webhook.
    |
    */

    'enabled' => env('NOTIFICATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of channels to use for failure notifications.
    | Supported: "mail", "slack"
    |
    */

    'channels' => env('NOTIFICATION_CHANNELS', 'mail'),

    /*
    |--------------------------------------------------------------------------
    | Mail Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the email recipient for failure notifications.
    |
    */

    'mail' => [
        'to' => env('NOTIFICATION_MAIL_TO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slack Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Slack webhook URL for failure notifications.
    | Create a webhook at: https://api.slack.com/messaging/webhooks
    |
    */

    'slack' => [
        'webhook_url' => env('NOTIFICATION_SLACK_WEBHOOK_URL'),
    ],

];
