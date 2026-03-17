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
}
