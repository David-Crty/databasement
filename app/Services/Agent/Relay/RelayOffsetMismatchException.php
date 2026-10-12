<?php

namespace App\Services\Agent\Relay;

use RuntimeException;

class RelayOffsetMismatchException extends RuntimeException
{
    public function __construct(public readonly int $received)
    {
        parent::__construct("The upload stands at byte {$received}.");
    }
}
