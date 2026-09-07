<?php

namespace App\Services\Backup\DTO;

use App\Enums\DatabaseType;
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
}
