<?php

namespace App\Exceptions\Backup;

class UnsupportedDatabaseTypeException extends BackupException
{
    public function __construct(string $databaseType)
    {
        parent::__construct("Database type '{$databaseType}' is not supported");
    }
}
