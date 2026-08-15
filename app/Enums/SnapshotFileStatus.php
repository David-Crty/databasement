<?php

namespace App\Enums;

enum SnapshotFileStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * The copy's file is being removed from its volume by a remote agent.
     * The snapshot record is kept until the agent confirms the removal.
     */
    case Deleting = 'deleting';

    /**
     * The agent could not remove the copy's file. The snapshot record is kept
     * so the file stays tracked and the deletion can be retried.
     */
    case DeletionFailed = 'deletion_failed';
}
