<?php

namespace App\Exceptions;

use RuntimeException;

class SshTunnelException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $sshErrorOutput = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
