<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Failure Notifications
    |--------------------------------------------------------------------------
    |
    | Configure notifications for backup and restore job failures.
    | Notifications can be sent via email, Slack webhook, and/or Discord.
    | Channels are automatically enabled when their configuration is set.
    |
    */

    'enabled' => env('NOTIFICATION_ENABLED', false),

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

    /*
    |--------------------------------------------------------------------------
    | Discord Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Discord bot token and channel ID for failure notifications.
    | Create a bot at: https://discord.com/developers/applications
    |
    */

    'discord' => [
        'token' => env('NOTIFICATION_DISCORD_BOT_TOKEN'),
        'channel_id' => env('NOTIFICATION_DISCORD_CHANNEL_ID'),
    ],

];
