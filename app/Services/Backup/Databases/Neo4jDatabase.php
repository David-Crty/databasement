<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;

class Neo4jDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * @return array<string>
     */
    public function listDatabases(): array
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $client = $this->createClient();
            $client->run('RETURN 1 AS ping');
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $version = 'unknown';
            try {
                $versionResults = $client->run('CALL dbms.components() YIELD name, versions');
                foreach ($versionResults as $record) {
                    if ($record->get('name') === 'Neo4j Kernel') {
                        $versions = $record->get('versions');
                        $version = is_array($versions) ? ($versions[0] ?? 'unknown') : 'unknown';
                        break;
                    }
                }
            } catch (\Exception) {
                // Non-critical — version info is optional
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'ping_ms' => $durationMs,
                    'output' => json_encode(['dbms' => "Neo4j {$version}"], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\Exception $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($durationMs >= 9500) {
                return [
                    'success' => false,
                    'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
                    'details' => [],
                ];
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'details' => [],
            ];
        }
    }

    protected function createClient(): ClientInterface
    {
        return ClientBuilder::create()
            ->withDriver(
                'default',
                sprintf('bolt://%s:%d', $this->config['host'], $this->config['port']),
                Authenticate::basic($this->config['user'], $this->config['pass'])
            )
            ->build();
    }
}
