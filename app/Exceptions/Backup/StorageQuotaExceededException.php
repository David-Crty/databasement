<?php

namespace App\Exceptions\Backup;

/**
 * Thrown when uploading a freshly-created backup would push a volume past its
 * configured storage limit. The backup is aborted before the file is uploaded
 * and the job is failed without retry — pruning old snapshots is left to the
 * user.
 */
class StorageQuotaExceededException extends BackupException {}
