<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Channel definitions: type => [name, config_keys].
     * Config keys map from NotificationChannel config key to AppConfig key.
     *
     * @var array<string, array{name: string, keys: array<string, string>}>
     */
    private const array CHANNELS = [
        'email' => [
            'name' => 'Email',
            'keys' => ['to' => 'notifications.mail.to'],
        ],
        'slack' => [
            'name' => 'Slack',
            'keys' => ['webhook_url' => 'notifications.slack.webhook_url'],
        ],
        'discord' => [
            'name' => 'Discord (Bot)',
            'keys' => [
                'token' => 'notifications.discord.token',
                'channel_id' => 'notifications.discord.channel_id',
            ],
        ],
        'discord_webhook' => [
            'name' => 'Discord (Webhook)',
            'keys' => ['url' => 'notifications.discord_webhook.url'],
        ],
        'telegram' => [
            'name' => 'Telegram',
            'keys' => [
                'bot_token' => 'notifications.telegram.bot_token',
                'chat_id' => 'notifications.telegram.chat_id',
            ],
        ],
        'pushover' => [
            'name' => 'Pushover',
            'keys' => [
                'token' => 'notifications.pushover.token',
                'user_key' => 'notifications.pushover.user_key',
            ],
        ],
        'gotify' => [
            'name' => 'Gotify',
            'keys' => [
                'url' => 'notifications.gotify.url',
                'token' => 'notifications.gotify.token',
            ],
        ],
        'webhook' => [
            'name' => 'Webhook',
            'keys' => [
                'url' => 'notifications.webhook.url',
                'secret' => 'notifications.webhook.secret',
            ],
        ],
    ];

    public function up(): void
    {
        // Load all notification config values from app_configs (raw, preserving encryption)
        $appConfigs = DB::table('app_configs')
            ->where('id', 'like', 'notifications.%')
            ->pluck('value', 'id')
            ->toArray();

        // Create NotificationChannel records for each configured channel type
        $now = now();

        foreach (self::CHANNELS as $type => $definition) {
            $config = [];
            $hasValue = false;

            foreach ($definition['keys'] as $configKey => $appConfigKey) {
                $value = $appConfigs[$appConfigKey] ?? null;
                $config[$configKey] = $value ?? '';

                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
            }

            if (! $hasValue) {
                continue;
            }

            DB::table('notification_channels')->insert([
                'id' => Str::ulid()->toBase32(),
                'name' => $definition['name'],
                'type' => $type,
                'config' => json_encode($config),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Set database server notification preferences based on notifications.enabled
        $notificationsEnabled = ($appConfigs['notifications.enabled'] ?? '0') === '1';

        DB::table('database_servers')->update([
            'notification_trigger' => $notificationsEnabled ? 'failure' : 'none',
            'notification_channel_selection' => 'all',
        ]);
    }

    public function down(): void
    {
        // Data migration — no automatic rollback
    }
};
