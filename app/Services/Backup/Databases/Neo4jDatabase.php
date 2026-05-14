<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Services\Backup\DTO\DatabaseOperationLog;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\DriverConfiguration;
use Laudis\Neo4j\Databags\SessionConfiguration;

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
        $client = $this->createClient();

        $file = fopen($outputPath, 'w');
        if ($file === false) {
            throw new \RuntimeException("Failed to open output file for writing: {$outputPath}");
        }

        try {
            $result = $client->readTransaction(
                function ($tsx) {
                    return $tsx->run(
                        'CALL apoc.export.cypher.all(null, {stream: true, format: "plain", useOptimizations: {type: "UNWIND_BATCH", unwindBatchSize: 50}}) YIELD cypherStatements RETURN cypherStatements'
                    );
                }
            );

            foreach ($result as $record) {
                fwrite($file, $record->get('cypherStatements'));
            }
        } finally {
            fclose($file);
        }

        return new DatabaseOperationResult(
            command: null,
            log: new DatabaseOperationLog('Neo4j APOC export completed', 'info'),
        );
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $cypher = file_get_contents($inputPath);
        if ($cypher === false) {
            throw new \RuntimeException("Failed to read restore file: {$inputPath}");
        }

        $client = $this->createClient();

        $client->writeTransaction(
            function ($tsx) use ($cypher): void {
                $tsx->run('CALL apoc.cypher.runMany($cypher, {})', ['cypher' => $cypher]);
            }
        );

        return new DatabaseOperationResult(
            command: null,
            log: new DatabaseOperationLog('Neo4j APOC restore completed', 'info'),
        );
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        $client = $this->createClient();

        $client->writeTransaction(
            function ($tsx): void {
                $tsx->run('CALL apoc.schema.assert({}, {})');
                $tsx->run('MATCH (n) DETACH DELETE n');
            }
        );
    }

    /**
     * @return array<string>
     */
    public function listDatabases(): array
    {
        $client = $this->createClient();
        $results = $client->run('SHOW DATABASES YIELD name WHERE name <> "system" RETURN name');

        $databases = [];
        foreach ($results as $record) {
            $databases[] = $record->get('name');
        }

        return $databases;
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
        } catch (\RuntimeException $e) {
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
            ->withDefaultDriverConfiguration(
                DriverConfiguration::default()->withAcquireConnectionTimeout(10.0)
            )
            ->withDefaultSessionConfiguration(
                SessionConfiguration::default()->withDatabase($this->config['database'])
            )
            ->build();
    }
}
