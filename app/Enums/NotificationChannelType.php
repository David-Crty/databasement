<?php

namespace App\Enums;

use Illuminate\Support\Facades\Crypt;

enum NotificationChannelType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Discord = 'discord';
    case DiscordWebhook = 'discord_webhook';
    case Telegram = 'telegram';
    case Pushover = 'pushover';
    case Gotify = 'gotify';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Slack => 'Slack',
            self::Discord => 'Discord (Bot)',
            self::DiscordWebhook => 'Discord (Webhook)',
            self::Telegram => 'Telegram',
            self::Pushover => 'Pushover',
            self::Gotify => 'Gotify',
            self::Webhook => 'Webhook',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Email => 'o-envelope',
            self::Slack => 'o-chat-bubble-left-right',
            self::Discord, self::DiscordWebhook => 'o-chat-bubble-oval-left',
            self::Telegram => 'o-paper-airplane',
            self::Pushover => 'o-device-phone-mobile',
            self::Gotify => 'o-bell',
            self::Webhook => 'o-globe-alt',
        };
    }

    /**
     * Fields that should be encrypted when storing in the database.
     *
     * @return string[]
     */
    public function sensitiveFields(): array
    {
        return match ($this) {
            self::Email => [],
            self::Slack => ['webhook_url'],
            self::Discord => ['token'],
            self::DiscordWebhook => ['url'],
            self::Telegram => ['bot_token'],
            self::Pushover => ['token', 'user_key'],
            self::Gotify => ['token'],
            self::Webhook => ['secret'],
        };
    }

    /**
     * Get the Laravel notification route key for this channel type.
     */
    public function routeKey(): string
    {
        return match ($this) {
            self::Email => 'mail',
            default => $this->value,
        };
    }

    /**
     * Get the route value from decrypted config for notification dispatch.
     *
     * @param  array<string, mixed>  $config
     */
    public function routeValue(array $config): ?string
    {
        return match ($this) {
            self::Email => $config['to'] ?? null,
            self::Slack => $config['webhook_url'] ?? null,
            self::Discord => $config['channel_id'] ?? null,
            self::DiscordWebhook, self::Gotify, self::Webhook => $config['url'] ?? null,
            self::Telegram => $config['chat_id'] ?? null,
            self::Pushover => $config['user_key'] ?? null,
        };
    }

    /**
     * Get a summary of the configuration for display in lists/tables.
     * Sensitive fields are excluded.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function configSummary(array $config): array
    {
        return match ($this) {
            self::Email => array_filter(['To' => $config['to'] ?? '']),
            self::Slack => ['Type' => 'Webhook'],
            self::Discord => array_filter(['Channel ID' => $config['channel_id'] ?? '']),
            self::DiscordWebhook => ['Type' => 'Webhook'],
            self::Telegram => array_filter(['Chat ID' => $config['chat_id'] ?? '']),
            self::Pushover => ['Type' => 'Push'],
            self::Gotify => array_filter(['URL' => $config['url'] ?? '']),
            self::Webhook => array_filter(['URL' => $config['url'] ?? '']),
        };
    }

    /**
     * Merge sensitive fields from persisted config when form values are empty.
     *
     * @param  array<string, mixed>  $formConfig
     * @param  array<string, mixed>  $persistedConfig
     * @return array<string, mixed>
     */
    public function mergeSensitiveFromPersisted(array $formConfig, array $persistedConfig): array
    {
        foreach ($this->sensitiveFields() as $field) {
            if (empty($formConfig[$field]) && ! empty($persistedConfig[$field])) {
                $formConfig[$field] = $persistedConfig[$field];
            }
        }

        return $formConfig;
    }

    /**
     * Encrypt sensitive fields, optionally preserving existing encrypted values.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $persistedEncrypted
     * @return array<string, mixed>
     */
    public function encryptSensitiveFields(array $config, array $persistedEncrypted = []): array
    {
        foreach ($this->sensitiveFields() as $field) {
            if (! empty($config[$field])) {
                $config[$field] = Crypt::encryptString($config[$field]);
            } elseif (! empty($persistedEncrypted[$field])) {
                $config[$field] = $persistedEncrypted[$field];
            }
        }

        return $config;
    }
}
