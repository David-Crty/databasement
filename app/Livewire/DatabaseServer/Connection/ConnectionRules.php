<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Enums\DatabaseType;
use App\Livewire\DatabaseServer\Form;

/**
 * Per-database-type connection knowledge for {@see Form}:
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
     * Shared rules (SSH tunnel, base fields) stay in Form.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(Form $form): array;

    /**
     * Validation rules for the standalone "Test Connection" action.
     *
     * @return array<string, mixed>
     */
    abstract public function testConnectionRules(Form $form): array;

    /**
     * Type-specific extra_config payload used for in-memory connection tests.
     *
     * @return array<string, mixed>
     */
    public function extraConfig(Form $form): array
    {
        return [];
    }

    /**
     * Type-specific additions to the dump-command preview config.
     *
     * @return array<string, mixed>
     */
    public function dumpPreviewConfig(Form $form): array
    {
        return [];
    }

    /**
     * Field defaults applied when the user switches the form to this type.
     */
    public function applyDefaults(Form $form): void {}
}
