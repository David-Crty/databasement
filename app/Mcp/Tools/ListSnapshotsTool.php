<?php

namespace App\Mcp\Tools;

use App\Models\Snapshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List backup snapshots, optionally filtered by database server. Returns the most recent snapshots first.')]
#[IsReadOnly]
class ListSnapshotsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $limit = min((int) ($request->get('limit') ?? 20), 100);

        $query = Snapshot::query()
            ->with(['databaseServer', 'volume', 'job'])
            ->orderByDesc('created_at');

        $serverId = $request->get('database_server_id');
        if ($serverId !== null) {
            $query->where('database_server_id', $serverId);
        }

        $snapshots = $query->limit($limit)->get();

        if ($snapshots->isEmpty()) {
            return Response::text('No snapshots found.');
        }

        $lines = $snapshots->map(function (Snapshot $snapshot) {
            $status = $snapshot->job->status;
            $size = $snapshot->getHumanFileSize();
            $date = $snapshot->created_at?->toDateTimeString() ?? 'unknown';
            $server = $snapshot->databaseServer->name;

            return "- **{$snapshot->database_name}** on {$server} (ID: {$snapshot->id})\n"
                ."  Status: {$status} | Size: {$size} | Date: {$date}\n"
                ."  Type: {$snapshot->database_type->label()} | Volume: {$snapshot->volume->name}";
        });

        return Response::text("Snapshots ({$snapshots->count()}):\n\n".implode("\n\n", $lines->all()));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'database_server_id' => $schema->string()
                ->description('Filter snapshots by database server ID.'),

            'limit' => $schema->integer()
                ->description('Maximum number of snapshots to return (default 20, max 100).')
                ->default(20),
        ];
    }
}
