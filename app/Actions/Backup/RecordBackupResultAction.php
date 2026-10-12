<?php

namespace App\Actions\Backup;

use App\Enums\SnapshotFileStatus;
use App\Models\Snapshot;
use App\Services\Backup\DTO\BackupResult;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RecordBackupResultAction
{
    use AsFake, AsObject;

    /**
     * Record what a backup run produced, whether or not it succeeded: the
     * archive, and which volumes it reached, so the copies that did upload
     * stay tracked. A run that produced no archive records only the volumes.
     */
    public function handle(Snapshot $snapshot, BackupResult $result): void
    {
        if ($result->filename !== '') {
            $snapshot->update([
                'filename' => $result->filename,
                'file_size' => $result->fileSize,
                'checksum' => $result->checksum,
            ]);
        }

        $files = $snapshot->files()->get();

        foreach ($result->volumeResults as $volumeResult) {
            $file = $files->firstWhere('volume_id', $volumeResult->volumeId);

            if ($file === null) {
                continue;
            }

            if ($volumeResult->status === SnapshotFileStatus::Completed) {
                $file->markUploaded();
            } else {
                $file->markUploadFailed($volumeResult->error);
            }
        }

        $snapshot->unsetRelation('files');
    }
}
