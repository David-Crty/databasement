<?php

namespace App\Services\Backup\DTO;

readonly class DatabaseOperationResult
{
    public function __construct(
        public ?string $command = null,
        public ?DatabaseOperationLog $log = null,
    ) {}
}
