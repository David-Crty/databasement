<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetJobStatusTool;
use App\Mcp\Tools\ListDatabaseServersTool;
use App\Mcp\Tools\ListSnapshotsTool;
use App\Mcp\Tools\TriggerBackupTool;
use App\Mcp\Tools\TriggerRestoreTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Databasement')]
#[Version('1.0.0')]
#[Instructions('Databasement manages database server backups. Use the provided tools to list database servers and their snapshots, trigger backups, restore snapshots to a target server, and check job status. Backup and restore operations are asynchronous — use get-job-status to poll for completion.')]
class DatabasementServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        ListDatabaseServersTool::class,
        ListSnapshotsTool::class,
        TriggerBackupTool::class,
        TriggerRestoreTool::class,
        GetJobStatusTool::class,
    ];
}
