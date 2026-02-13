<?php

namespace App\Services\Backup\Databases\DTO;

class DatabaseOperationResult
{
    public function __construct(
        public readonly ?string $command = null,
        public readonly ?DatabaseOperationLog $log = null,
    ) {}
}
