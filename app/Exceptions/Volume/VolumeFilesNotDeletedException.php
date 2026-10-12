<?php

namespace App\Exceptions\Volume;

use App\Models\Volume;
use Exception;

/**
 * Some snapshot files could not be removed from a volume, so the volume and
 * its snapshots were kept to keep tracking them.
 */
class VolumeFilesNotDeletedException extends Exception
{
    /**
     * @param  list<string>  $errors  One distinct error per kind of failure
     */
    public function __construct(public readonly Volume $volume, public readonly array $errors)
    {
        $details = implode('; ', $errors);

        parent::__construct("Volume {$volume->name} was kept because some of its files could not be deleted ({$details}).");
    }
}
