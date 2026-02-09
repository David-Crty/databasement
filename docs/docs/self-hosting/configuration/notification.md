---
sidebar_position: 4
---

# Notification

Notification settings (enable/disable, channels, recipients) can be configured directly from the **Configuration** page in the web UI.

This page covers additional setup guides for each channel.

## Email {#email}

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

## Slack {#slack}

To receive failure notifications in Slack, you need to create an Incoming Webhook:

1. Go to [Slack API Apps](https://api.slack.com/apps)
2. Click **Create New App** > **From scratch**
3. Name your app (e.g., "Databasement") and select your workspace
4. Go to **Incoming Webhooks** and toggle it on
5. Click **Add New Webhook to Workspace**
6. Select the channel where you want notifications
7. Copy the webhook URL and paste it in the Configuration page

## Discord {#discord}

To receive failure notifications in Discord, you need a bot token and a channel ID.

### Creating a Discord Bot

1. Go to [Discord Developer Portal](https://discord.com/developers/applications)
2. Click **New Application** and give it a name (e.g., "Databasement")
3. Go to **Bot** in the sidebar and click **Add Bot**
4. Under **Token**, click **Copy** to get your bot token
5. Go to **OAuth2** > **URL Generator**
6. Select scopes: `bot`
7. Select bot permissions: `Send Messages`, `Embed Links`
8. Copy the generated URL and open it to invite the bot to your server

### Getting a Channel ID

1. Open Discord and go to **User Settings** > **Advanced**
2. Enable **Developer Mode**
3. Right-click the channel where you want notifications
4. Click **Copy Channel ID**

Enter both the **Bot Token** and **Channel ID** on the Configuration page.

## Telegram {#telegram}

To receive failure notifications via Telegram, you need a bot token and a chat ID.

### Creating a Telegram Bot

1. Open Telegram and search for **@BotFather**
2. Send `/newbot` and follow the prompts to name your bot
3. BotFather will give you a **Bot Token** — copy it

### Getting a Chat ID

1. Add your bot to the group or start a direct chat with it
2. Send a message to the bot
3. Open `https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates` in a browser
4. Find the `chat.id` value in the JSON response

Enter both the **Bot Token** and **Chat ID** on the Configuration page.

## Pushover {#pushover}

[Pushover](https://pushover.net/) delivers push notifications to your phone and desktop.

1. Create an account at [pushover.net](https://pushover.net/)
2. Copy your **User Key** from the dashboard
3. Go to **Create an Application/API Token**
4. Name it (e.g., "Databasement") and copy the **App Token**

Enter both the **App Token** and **User Key** on the Configuration page.

## Gotify {#gotify}

[Gotify](https://gotify.net/) is a self-hosted push notification server.

1. Log in to your Gotify server
2. Go to **Apps** and create a new application (e.g., "Databasement")
3. Copy the **App Token**

Enter your **Gotify Server URL** (e.g., `https://gotify.example.com`) and the **App Token** on the Configuration page.

## Webhook {#webhook}

Send failure notifications as JSON payloads to any HTTP endpoint.

Enter your **Webhook URL** on the Configuration page. Optionally, provide a **Webhook Secret** to authenticate requests via the `X-Webhook-Token` header.

### Request Format

Notifications are sent as `POST` requests with a JSON body:

```json
{
  "event": "notification.failed",
  "title": "Backup Failed: Production DB",
  "body": "A backup job has failed.",
  "fields": {
    "Server": "Production DB",
    "Database": "myapp"
  },
  "error": "Connection refused",
  "action_url": "https://your-instance.com/backup-jobs/...",
  "timestamp": "2025-01-15T02:00:00+00:00"
}
```

### Headers

| Header | Description |
|--------|-------------|
| `Content-Type` | `application/json` |
| `X-Webhook-Event` | Notification class name (e.g., `BackupFailedNotification`) |
| `X-Webhook-Token` | The configured secret (only if a secret is configured) |

## What Gets Notified

Notifications are sent only for **failures**:

- **Backup failures**: When a scheduled or manual backup fails
- **Restore failures**: When a restore operation fails
- **Missing snapshots**: When snapshot file verification detects missing backup files on storage volumes

Successful operations do not trigger notifications.

## Notification Content

Each notification includes:

- Server name
- Database name
- Error message
- Timestamp
- Direct link to the failed job details
