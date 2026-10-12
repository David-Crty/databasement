<?php

namespace App\Support;

use App\Enums\DatabaseType;

/**
 * Persistence shape of a database server's type-specific settings, shared by
 * the create and update actions.
 */
class DatabaseServerExtraConfig
{
    /**
     * Move type-specific fields (auth_source, srv_enabled, connection_options,
     * dump_flags, ssl_enabled, connection_database) into extra_config.
     * Clears stale keys when database type has changed.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $existingExtraConfig
     */
    public static function fold(array &$data, ?array $existingExtraConfig = null, ?string $previousType = null): void
    {
        $type = $data['database_type'] ?? '';

        // Reset extra_config when type changes to avoid stale keys
        $extraConfig = ($previousType !== null && $previousType !== $type) ? [] : ($existingExtraConfig ?? []);

        // Each rule: [key, keep?, store]. $keep decides whether the field is
        // relevant for the current type and worth persisting; $store derives the
        // stored value. Keys absent from $data are left untouched (see pullExtraConfigKey).
        $rules = [
            ['auth_source',         fn ($v) => $type === DatabaseType::MONGODB->value && $v !== '' && $v !== null, fn ($v) => $v],
            ['srv_enabled',         fn ($v) => $type === DatabaseType::MONGODB->value && $v,                       fn () => true],
            ['connection_options',  fn ($v) => $type === DatabaseType::MONGODB->value && $v !== '' && $v !== null, fn ($v) => $v],
            ['dump_flags',          fn ($v) => $type !== DatabaseType::SQLITE->value && $v !== '' && $v !== null,  fn ($v) => $v],
            ['dump_format',         fn ($v) => $type === DatabaseType::POSTGRESQL->value && $v === 'custom',       fn () => 'custom'],
            ['dump_privileges',     fn ($v) => $type === DatabaseType::POSTGRESQL->value && $v,                    fn () => true],
            ['ssl_enabled',         fn ($v) => in_array($type, [DatabaseType::MYSQL->value, DatabaseType::POSTGRESQL->value], true) && $v, fn () => true],
            ['connection_database', fn ($v) => $type === DatabaseType::POSTGRESQL->value && is_string($v) && trim($v) !== '', fn ($v) => trim($v)],
        ];

        foreach ($rules as [$key, $keep, $store]) {
            self::pullExtraConfigKey($data, $extraConfig, $key, $keep, $store);
        }

        $data['extra_config'] = $extraConfig ?: null;
    }

    /**
     * Pull a single field out of $data and fold it into $extraConfig.
     *
     * When the key is present in $data it is removed from $data and either
     * stored in $extraConfig (using $store to derive the value) if $keep
     * returns true, or cleared from $extraConfig otherwise. Keys absent from
     * $data are left untouched.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extraConfig
     * @param  callable(mixed): bool  $keep
     * @param  callable(mixed): mixed  $store
     */
    private static function pullExtraConfigKey(array &$data, array &$extraConfig, string $key, callable $keep, callable $store): void
    {
        if (! array_key_exists($key, $data)) {
            return;
        }

        $value = $data[$key];
        unset($data[$key]);

        if ($keep($value)) {
            $extraConfig[$key] = $store($value);
        } else {
            unset($extraConfig[$key]);
        }
    }
}
