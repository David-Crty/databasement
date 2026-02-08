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
- Direct link to the failed job details
