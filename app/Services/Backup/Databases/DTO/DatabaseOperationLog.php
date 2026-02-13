<?php

namespace App\Services\Backup\Databases\DTO;

class DatabaseOperationLog
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public readonly string $message,
        public readonly string $level = 'info',
        public readonly ?array $context = null,
    ) {}
}
