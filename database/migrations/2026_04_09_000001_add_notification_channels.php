<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Channel definitions for migrating AppConfig entries to NotificationChannel records.
     * Maps type => [name, config_keys (NotificationChannel key => AppConfig key)].
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
        // 1. Create notification_channels table
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('type');
            $table->json('config');
            $table->timestamps();
        });

        // 2. Add notification settings to database_servers
        Schema::table('database_servers', function (Blueprint $table) {
            $table->string('notification_trigger')->default('failure');
            $table->string('notification_channel_selection')->default('all');
        });

        // 3. Create pivot table
        Schema::create('database_server_notification_channel', function (Blueprint $table) {
            $table->char('database_server_id', 26);
            $table->char('notification_channel_id', 26);

            $table->foreign('database_server_id', 'db_server_notif_ch_server_fk')
                ->references('id')->on('database_servers')
                ->cascadeOnDelete();

            $table->foreign('notification_channel_id', 'db_server_notif_ch_channel_fk')
                ->references('id')->on('notification_channels')
                ->cascadeOnDelete();

            $table->unique(['database_server_id', 'notification_channel_id'], 'db_server_notif_channel_unique');
        });

        // 4. Migrate existing AppConfig notification entries to NotificationChannel records
        $appConfigs = DB::table('app_configs')
            ->where('id', 'like', 'notifications.%')
            ->pluck('value', 'id')
            ->toArray();

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

        // 5. Remove old notification AppConfig entries
        DB::table('app_configs')
            ->where('id', 'like', 'notifications.%')
            ->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('database_server_notification_channel');
        Schema::dropIfExists('notification_channels');

        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn(['notification_trigger', 'notification_channel_selection']);
        });
    }
};
