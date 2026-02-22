<?php

namespace App\Services\Backup\DTO;

readonly class DatabaseOperationLog
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public string $message,
        public string $level = 'info',
        public ?array $context = null,
    ) {}
}
