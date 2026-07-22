<?php

namespace App\Livewire\Forms\Connection;

use App\Enums\DatabaseType;
use App\Livewire\Forms\DatabaseServerForm;

/**
 * Per-database-type connection knowledge for {@see DatabaseServerForm}:
 * validation rules, connection-test rules, extra_config payloads and field
 * defaults.
 *
 * Adding a database type means wiring its case in {@see self::for()} (a new
 * subclass, or {@see ClientServerConnectionRules} when host/port/credentials
 * suffice) plus a partial under
 * resources/views/livewire/database-server/connection/{type}.blade.php.
 */
abstract class ConnectionRules
{
    public static function for(?DatabaseType $type): self
    {
        return match ($type) {
            DatabaseType::MYSQL => new MysqlConnectionRules,
            DatabaseType::POSTGRESQL => new PostgresConnectionRules,
            DatabaseType::SQLITE => new SqliteConnectionRules,
            DatabaseType::REDIS => new RedisConnectionRules,
            DatabaseType::MONGODB => new MongodbConnectionRules,
            DatabaseType::MSSQL, DatabaseType::FIREBIRD, null => new ClientServerConnectionRules,
        };
    }

    /**
     * Validation rules for the connection fields during full-form validation.
     * Shared rules (SSH tunnel, base fields) stay in DatabaseServerForm.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(DatabaseServerForm $form): array;

    /**
     * Validation rules for the standalone "Test Connection" action.
     *
     * @return array<string, mixed>
     */
    abstract public function testConnectionRules(DatabaseServerForm $form): array;

    /**
     * Type-specific extra_config payload used for in-memory connection tests.
     *
     * @return array<string, mixed>
     */
    public function extraConfig(DatabaseServerForm $form): array
    {
        return [];
    }

    /**
     * Type-specific additions to the dump-command preview config.
     *
     * @return array<string, mixed>
     */
    public function dumpPreviewConfig(DatabaseServerForm $form): array
    {
        return [];
    }

    /**
     * Field defaults applied when the user switches the form to this type.
     */
    public function applyDefaults(DatabaseServerForm $form): void {}
}
