<?php

namespace App\Services\Backup\DTO;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\BackupException;
use App\Exceptions\Backup\DatabaseDumpException;
use App\Rules\SafeDumpFlags;

readonly class DatabaseOperationResult
{
    /**
     * @param  bool  $writesToStdout  The dump command writes to stdout instead of the output path, so the
     *                                caller pipes it straight into the compressor and no uncompressed dump
     *                                ever lands on disk.
     */
    public function __construct(
        public ?string $command = null,
        public ?DatabaseOperationLog $log = null,
        public bool $writesToStdout = false,
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
}
