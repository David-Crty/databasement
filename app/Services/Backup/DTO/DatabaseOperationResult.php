<?php

namespace App\Services\Backup\DTO;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\BackupException;
use App\Exceptions\Backup\DatabaseDumpException;
use App\Rules\SafeDumpFlags;

readonly class DatabaseOperationResult
{
    public function __construct(
        public ?string $command = null,
        public ?DatabaseOperationLog $log = null,
    ) {}

    /**
     * Escape user-provided dump flags by individually quoting each token.
     *
     * Quoting stops the shell from reading the tokens, not the dump client, so
     * an output-redirecting option is refused here as well as at validation.
     * Stored configurations are not revalidated, so this is the only check that
     * sees them.
     *
     * @throws DatabaseDumpException
     */
    public static function escapeFlags(string $flags, DatabaseType $type): string
    {
        $violation = SafeDumpFlags::violation($flags, $type);

        if ($violation !== null) {
            throw new DatabaseDumpException(
                "Dump flag '{$violation}' is not allowed: it redirects where the dump is written."
            );
        }

        return implode(' ', array_map('escapeshellarg', SafeDumpFlags::tokenize($flags)));
    }

    /**
     * Quote a database name or path for a client command line.
     *
     * Shell quoting does not stop a client from reading a leading dash as an
     * option, and names discovered on the server or stored before validation
     * existed never pass through a form, so they are checked here.
     *
     * @throws BackupException
     */
    public static function escapeDatabaseName(string $name): string
    {
        if (str_starts_with($name, '-')) {
            throw new BackupException("Database name '{$name}' is not supported: it must not start with a dash.");
        }

        return escapeshellarg($name);
    }

    /**
     * Expand excluded table names into one repeated CLI flag per table, each
     * escaped and prefixed with a space so the result can be concatenated onto
     * an existing flag string.
     *
     * The qualifier prefixes every name, letting MySQL scope the exclusion to
     * the schema being dumped (`--ignore-table=mydb.logs`) while PostgreSQL
     * leaves it empty so the pattern matches the table in any schema.
     *
     * The quote wraps each name after the qualifier: pg_dump folds an unquoted
     * pattern to lower case, so a double-quoted name keeps it an exact,
     * case-sensitive match.
     *
     * @param  mixed  $tables  Raw extra_config value; anything but a list of strings yields ''.
     */
    public static function escapeTableExclusions(string $flag, mixed $tables, string $qualifier = '', string $quote = ''): string
    {
        if (! is_array($tables)) {
            return '';
        }

        return implode('', array_map(
            fn (string $table): string => ' '.escapeshellarg($flag.'='.$qualifier.$quote.$table.$quote),
            array_filter($tables, is_string(...)),
        ));
    }
}
