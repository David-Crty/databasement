<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Monolog\Handler\FilterHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;

/**
 * Log channel tap for stdio_stdout: warnings and above belong to
 * stdio_stderr, so this channel's handlers drop them.
 */
class KeepStdoutBelowWarning
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $monolog->setHandlers(array_map(
            fn ($handler) => new FilterHandler($handler, Level::Debug, Level::Notice),
            $monolog->getHandlers(),
        ));
    }
}
