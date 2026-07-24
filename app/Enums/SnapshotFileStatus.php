<?php

namespace App\Enums;

enum SnapshotFileStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
