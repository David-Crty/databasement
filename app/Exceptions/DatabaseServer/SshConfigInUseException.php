<?php

namespace App\Exceptions\DatabaseServer;

use App\Models\DatabaseServerSshConfig;
use Exception;

/**
 * Database servers still connect through an SSH configuration, so it cannot
 * be deleted.
 */
class SshConfigInUseException extends Exception
{
    public function __construct(public readonly DatabaseServerSshConfig $sshConfig)
    {
        parent::__construct('This SSH configuration is still used by one or more database servers.');
    }
}
