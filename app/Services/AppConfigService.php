<?php

namespace App\Services;

use App\Models\AppConfig;

class AppConfigService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * Default values for all known config keys.
     *
     * Used as a fallback when the DB row is missing (e.g. pre-migration).
     *
     * @var array<string, mixed>
     */
    private const array DEFAULTS = [
        'backup.working_directory' => '/tmp/backups',
        'backup.compression' => 'gzip',
        'backup.compression_level' => 6,
        'backup.job_timeout' => 7200,
        'backup.job_tries' => 3,
        'backup.job_backoff' => 60,
        'backup.daily_cron' => '0 2 * * *',
        'backup.weekly_cron' => '0 3 * * 0',
        'backup.cleanup_cron' => '0 4 * * *',
        'backup.verify_files' => true,
        'backup.verify_files_cron' => '0 5 * * *',
        'notifications.enabled' => false,
        'notifications.mail.to' => null,
        'notifications.slack.webhook_url' => null,
        'notifications.discord.token' => null,
        'notifications.discord.channel_id' => null,
    ];

    /**
     * Get a config value by key.
     *
     * Checks in-memory cache first, then DB, then falls back to DEFAULTS.
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

        return $default ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * Set a config value by key.
     */
    public function set(string $key, mixed $value): void
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        $row = AppConfig::findOrFail($key);

        $row->update([
            'value' => AppConfig::prepareValue($value, $row->is_sensitive),
        ]);

        $this->cache[$key] = $row->refresh()->getCastedValue();
    }

    /**
     * Clear the in-memory cache.
     */
    public function flush(): void
    {
        $this->cache = [];
    }
}
