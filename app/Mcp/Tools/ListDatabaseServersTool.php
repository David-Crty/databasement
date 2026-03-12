<?php

namespace App\Mcp\Tools;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List all registered database servers with their connection details and backup configuration.')]
#[IsReadOnly]
class ListDatabaseServersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $query = DatabaseServer::query()->with(['backup.volume', 'backup.backupSchedule']);

        $type = $request->get('database_type');
        if ($type !== null) {
            $query->where('database_type', $type);
        }

        $servers = $query->orderBy('name')->get();

        if ($servers->isEmpty()) {
            return Response::text('No database servers found.');
        }

        $lines = $servers->map(function (DatabaseServer $server) {
            $parts = [
                "- **{$server->name}** (ID: {$server->id})",
                "  Type: {$server->database_type->label()}",
                "  Connection: {$server->getConnectionLabel()}",
            ];

            $backup = $server->backup;
            if ($backup !== null) {
                $parts[] = "  Backup: configured (volume: {$backup->volume->name})";
                $parts[] = "  Schedule: {$backup->backupSchedule->expression}";
            } else {
                $parts[] = '  Backup: not configured';
            }

            $parts[] = '  Backups enabled: '.($server->backups_enabled ? 'yes' : 'no');

            return implode("\n", $parts);
        });

        return Response::text("Database Servers ({$servers->count()}):\n\n".implode("\n\n", $lines->all()));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'database_type' => $schema->string()
                ->enum(array_map(fn (DatabaseType $t) => $t->value, DatabaseType::cases()))
                ->description('Filter by database type (e.g. mysql, postgres, sqlite, redis, mongodb).'),
        ];
    }
}
