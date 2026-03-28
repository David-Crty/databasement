# Notifications Module

Failure-only notification system. Sends alerts when backups, restores, or file-verification jobs fail. There are no success notifications.

## Architecture

```
BaseFailedNotification (abstract)          FailedNotificationMessage
  ├─ BackupFailedNotification                 (renders to all channels)
  ├─ RestoreFailedNotification
  └─ SnapshotsMissingNotification
```

**Key design decision:** Concrete notifications only define `getMessage()` — they never touch channel rendering. All `to{Channel}()` methods live in `BaseFailedNotification` and delegate to `FailedNotificationMessage`. Adding a new notification type requires zero channel awareness.

## Channel Registry

Channels are mapped by route key in `BaseFailedNotification::CHANNEL_MAP`. The route key (e.g. `discord_webhook`) must match exactly in three places:

| Where | What to add |
|---|---|
| `CHANNEL_MAP` in `BaseFailedNotification` | `'key' => ChannelClass::class` |
| `getNotificationRoutes()` in `FailureNotificationService` | `'key' => AppConfig::get(...)` |
| `AppConfigService::CONFIG` | Config key definitions |

## Channels

| Route Key | Channel Class | Transport | Config (AppConfig keys) |
|---|---|---|---|
| `mail` | Laravel built-in | SMTP | `notifications.mail.to` |
| `slack` | Laravel built-in | Webhook POST | `notifications.slack.webhook_url` |
| `discord` | `DiscordChannel` (vendor) | Bot API | `notifications.discord.token`, `.channel_id` |
| `discord_webhook` | `DiscordWebhookChannel` | Webhook POST | `notifications.discord_webhook.url` |
| `telegram` | `TelegramChannel` (vendor) | Bot API | `notifications.telegram.bot_token`, `.chat_id` |
| `pushover` | `PushoverChannel` (vendor) | HTTP API | `notifications.pushover.token`, `.user_key` |
| `gotify` | `GotifyChannel` | HTTP API | `notifications.gotify.url`, `.token` |
| `webhook` | `WebhookChannel` | HTTP POST | `notifications.webhook.url`, `.secret` |

**Two kinds of channels:**
- **Vendor channels** (discord, telegram, pushover): Read tokens from `config('services.*')`. Require boot-time registration in `AppServiceProvider::registerNotificationServiceConfigs()` AND refresh in `FailureNotificationService::refreshChannelServiceConfigs()` (critical for Octane).
- **Custom channels** (gotify, discord_webhook, webhook): Read config directly from `AppConfig` in their `send()` method. No service config registration needed.

## How to Add a New Channel

1. Add config keys to `AppConfigService::CONFIG` (set `is_sensitive` correctly)
2. Create `Channels/{Name}Channel.php` — implement `send(object $notifiable, Notification $notification): void`
3. Add `to{Name}()` to `FailedNotificationMessage` (rendering) and `BaseFailedNotification` (delegation)
4. Add entry to `BaseFailedNotification::CHANNEL_MAP`
5. Add route in `FailureNotificationService::getNotificationRoutes()`
6. If vendor channel: register token in `AppServiceProvider` + add to `refreshChannelServiceConfigs()`
7. Add form properties + validation + save/clear in `ConfigurationForm`
8. Add to `getChannelOptions()` in `Configuration/Index.php`
9. Add UI fields in `configuration/index.blade.php`
10. Add to test datasets in `FailureNotificationTest.php` and `ConfigurationTest.php`
11. Add translation keys to `lang/*.json`
12. Add setup guide in `docs/docs/self-hosting/configuration/notification.md`

## Anti-patterns

- **Channels should use `->throw()` on HTTP responses** — consistent with Laravel's Slack channel. `FailureNotificationService::send()` does NOT catch exceptions; they bubble up to callers. `sendTestNotification()` has its own try/catch to show errors in the UI. Production callers (queue job `failed()` methods) are protected by Laravel's `Worker::runJob()` catch-all.
- **Never read tokens from `config('services.*')` in custom channels** — use `AppConfig::get()` directly. The services config may be stale under Octane.
- **Never add channel-specific rendering in concrete notifications** — all rendering goes through `FailedNotificationMessage`. Concrete notifications only define `getMessage()`.
- **Never use `$notifiable->routeNotificationFor()` in custom channels** — that pattern is for vendor channels. Custom channels fetch their own config from `AppConfig`.
- **Never add config keys without updating `AppConfigService::CONFIG`** — `AppConfig::set()` throws if the key is not registered.
