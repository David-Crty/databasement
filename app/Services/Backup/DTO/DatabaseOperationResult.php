<?php

namespace App\Services\Backup\DTO;

readonly class DatabaseOperationResult
{
    public function __construct(
        public ?string $command = null,
        public ?DatabaseOperationLog $log = null,
    ) {}

    /**
     * Escape user-provided dump flags by individually quoting each token.
     */
    public static function escapeFlags(string $flags): string
    {
        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/', trim($flags), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map('escapeshellarg', $tokens));
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
     * @param  mixed  $tables  Raw extra_config value; anything but a list of strings yields ''.
     */
    public static function escapeTableExclusions(string $flag, mixed $tables, string $qualifier = ''): string
    {
        if (! is_array($tables)) {
            return '';
        }

        return implode('', array_map(
            fn (string $table): string => ' '.escapeshellarg($flag.'='.$qualifier.$table),
            array_filter($tables, is_string(...)),
        ));
    }
}
