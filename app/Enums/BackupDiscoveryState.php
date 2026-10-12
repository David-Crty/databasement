<?php

namespace App\Enums;

/**
 * Whether triggering a backup had to ask the server's agent to list its
 * databases first, which delays the snapshots until the agent reports back.
 */
enum BackupDiscoveryState
{
    case NotNeeded;
    case Dispatched;
    case AlreadyRunning;
}
