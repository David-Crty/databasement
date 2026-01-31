---
sidebar_position: 6
---

# Notifications

Databasement can send notifications when backup or restore jobs fail. Notifications help you stay informed about issues that require attention.

## Configuration

| Variable | Description | Default |
|----------|-------------|---------|
| `NOTIFICATION_ENABLED` | Enable failure notifications | `false` |
| `NOTIFICATION_CHANNELS` | Comma-separated list of channels: `mail`, `slack` | `mail` |
| `NOTIFICATION_MAIL_TO` | Email address for failure notifications | - |
| `NOTIFICATION_SLACK_WEBHOOK_URL` | Slack webhook URL for failure notifications | - |

## Email Notifications

To receive failure notifications via email:

1. Configure your [mail settings](https://laravel.com/docs/mail) (SMTP, etc.)
2. Enable notifications and set the recipient:

```bash
NOTIFICATION_ENABLED=true
NOTIFICATION_CHANNELS=mail
NOTIFICATION_MAIL_TO=admin@example.com
```

### Mail Configuration

Databasement uses Laravel's mail system. Configure your mail driver with these environment variables:

```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=databasement@example.com
MAIL_FROM_NAME="Databasement"
```

## Slack Notifications

To receive failure notifications in Slack:

1. Create an [Incoming Webhook](https://api.slack.com/messaging/webhooks) in your Slack workspace
2. Copy the webhook URL
3. Configure Databasement:

```bash
NOTIFICATION_ENABLED=true
NOTIFICATION_CHANNELS=slack
NOTIFICATION_SLACK_WEBHOOK_URL=https://hooks.slack.com/XXXXXXXXXXXXXXXXXXXXXXXX
```

### Creating a Slack Webhook

1. Go to [Slack API Apps](https://api.slack.com/apps)
2. Click **Create New App** > **From scratch**
3. Name your app (e.g., "Databasement") and select your workspace
4. Go to **Incoming Webhooks** and toggle it on
5. Click **Add New Webhook to Workspace**
6. Select the channel where you want notifications
7. Copy the webhook URL

## Multiple Channels

To send notifications to both email and Slack:

```bash
NOTIFICATION_ENABLED=true
NOTIFICATION_CHANNELS=mail,slack
NOTIFICATION_MAIL_TO=admin@example.com
NOTIFICATION_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
```

## What Gets Notified

Notifications are sent only for **failures**:

- **Backup failures**: When a scheduled or manual backup fails
- **Restore failures**: When a restore operation fails

Successful operations do not trigger notifications.

## Notification Content

Each notification includes:

- Server name
- Database name
- Error message
- Timestamp
- Direct link to the failed job details (email only)

When you click the "View Job Details" button in the email, it opens the Jobs page with the logs modal automatically displayed for that specific job.
