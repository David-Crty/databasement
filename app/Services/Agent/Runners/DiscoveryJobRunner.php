<?php

namespace App\Services\Agent\Runners;

use App\Enums\AgentJobType;
use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Agent\AgentApiClient;
use App\Services\Backup\Databases\DatabaseProvider;
use Closure;

class DiscoveryJobRunner implements AgentJobRunner
{
    public function __construct(
        private DatabaseProvider $databaseProvider,
    ) {}

    public function type(): AgentJobType
    {
        return AgentJobType::Discover;
    }

    public function run(array $job, AgentApiClient $client, Closure $log): void
    {
        try {
            $payload = $job['payload'];
            $serverName = $payload['server_name'] ?? 'unknown';

            $log("Processing discovery job {$job['id']}: {$serverName}");

            $databaseType = DatabaseType::from($payload['database']['type'] ?? DatabaseType::MYSQL->value);

            $tempServer = DatabaseServer::forConnectionTest([
                'database_type' => $databaseType->value,
                'host' => $payload['database']['host'] ?? '',
                'port' => $payload['database']['port'] ?? $databaseType->defaultPort(),
                'username' => $payload['database']['username'] ?? '',
                'password' => $payload['database']['password'] ?? '',
                'extra_config' => $payload['database']['extra_config'] ?? null,
            ]);

            $databases = $this->databaseProvider->listDatabasesForServer($tempServer);

            if (($payload['selection_mode'] ?? '') === 'pattern' && ! empty($payload['pattern'])) {
                $databases = DatabaseServer::filterDatabasesByPattern($databases, $payload['pattern']);
            }

            // Reported through the dedicated endpoint rather than ack, which
            // servers up to 1.8 refuse for discovery jobs.
            $client->reportDiscoveredDatabases($job['id'], $databases);
            $log('Discovery completed: '.count($databases).' database(s) found');
        } catch (\Throwable $e) {
            $log("Discovery failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage());
        }
    }
}
