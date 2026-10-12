<?php

namespace App\Exceptions\DatabaseServer;

use App\Models\DatabaseServer;
use Exception;

/**
 * Some of a server's backup files could not be removed from their volumes,
 * so the server and its snapshots were kept to keep tracking them.
 */
class DatabaseServerFilesNotDeletedException extends Exception
{
    /**
     * @param  list<string>  $errors  One "volume: error" line per kind of failure
     */
    public function __construct(public readonly DatabaseServer $server, public readonly array $errors)
    {
        $details = implode('; ', $errors);

        parent::__construct("Database server {$server->name} was kept because some of its backup files could not be deleted ({$details}).");
    }
}
