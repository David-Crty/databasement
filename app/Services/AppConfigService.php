<?php

namespace App\Services;

use App\Models\AppConfig;

class AppConfigService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * Config key definitions: type, sensitivity, and default value.
     *
     * Used for fallback defaults (pre-migration) and auto-creating rows on `set()`.
     *
     * @var array<string, array{type: string, is_sensitive: bool, default: mixed}>
     */
    private const array CONFIG = [
        'backup.working_directory' => ['type' => 'string', 'is_sensitive' => false, 'default' => '/tmp/backups'],
        'backup.compression' => ['type' => 'string', 'is_sensitive' => false, 'default' => 'gzip'],
        'backup.compression_level' => ['type' => 'integer', 'is_sensitive' => false, 'default' => 6],
        'backup.job_timeout' => ['type' => 'integer', 'is_sensitive' => false, 'default' => 7200],
        'backup.job_tries' => ['type' => 'integer', 'is_sensitive' => false, 'default' => 3],
        'backup.job_backoff' => ['type' => 'integer', 'is_sensitive' => false, 'default' => 60],
        'backup.daily_cron' => ['type' => 'string', 'is_sensitive' => false, 'default' => '0 2 * * *'],
        'backup.weekly_cron' => ['type' => 'string', 'is_sensitive' => false, 'default' => '0 3 * * 0'],
        'backup.cleanup_cron' => ['type' => 'string', 'is_sensitive' => false, 'default' => '0 4 * * *'],
        'backup.verify_files' => ['type' => 'boolean', 'is_sensitive' => false, 'default' => true],
        'backup.verify_files_cron' => ['type' => 'string', 'is_sensitive' => false, 'default' => '0 5 * * *'],
        'notifications.enabled' => ['type' => 'boolean', 'is_sensitive' => false, 'default' => false],
        'notifications.mail.to' => ['type' => 'string', 'is_sensitive' => false, 'default' => null],
        'notifications.slack.webhook_url' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
        'notifications.discord.token' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
        'notifications.discord.channel_id' => ['type' => 'string', 'is_sensitive' => false, 'default' => null],
        'notifications.telegram.bot_token' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
        'notifications.telegram.chat_id' => ['type' => 'string', 'is_sensitive' => false, 'default' => null],
        'notifications.pushover.token' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
        'notifications.pushover.user_key' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
        'notifications.gotify.url' => ['type' => 'string', 'is_sensitive' => false, 'default' => null],
        'notifications.gotify.token' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
        'notifications.webhook.url' => ['type' => 'string', 'is_sensitive' => false, 'default' => null],
        'notifications.webhook.secret' => ['type' => 'string', 'is_sensitive' => true, 'default' => null],
    ];

    /**
     * Get a config value by key.
     *
     * Checks in-memory cache first, then DB, then falls back to CONFIG defaults.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $row = AppConfig::find($key);

            if ($row) {
                $value = $row->getCastedValue();
                $this->cache[$key] = $value;

                return $value;
            }
        } catch (\Throwable) {
            // Table may not exist yet (pre-migration) — fall through to defaults
        }

        return $default ?? self::CONFIG[$key]['default'] ?? null;
    }

    /**
     * Set a config value by key.
     *
     * Auto-creates the row from CONFIG if it doesn't exist yet.
     */
    public function set(string $key, mixed $value): void
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (! array_key_exists($key, self::CONFIG)) {
            throw new \InvalidArgumentException("Unknown config key [{$key}]. Add it to AppConfigService::CONFIG.");
        }

        $schema = self::CONFIG[$key];

        $row = AppConfig::updateOrCreate(
            ['id' => $key],
            [
                'value' => AppConfig::prepareValue($value, $schema['is_sensitive']),
                'type' => $schema['type'],
                'is_sensitive' => $schema['is_sensitive'],
            ]
        );

        $this->cache[$key] = $row->getCastedValue();
    }

    /**
     * Clear the in-memory cache.
     */
    public function flush(): void
    {
        $this->cache = [];
    }
}
