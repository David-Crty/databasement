<?php

namespace App\Exceptions\Backup;

use Exception;

/**
 * The server no longer accepts reports for this job (it was failed, deleted
 * or handed to another agent): whatever runs it must stop.
 */
class JobRevokedException extends Exception {}
