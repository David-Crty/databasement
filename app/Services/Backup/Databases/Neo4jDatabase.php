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
use Laudis\Neo4j\Enum\SocketType;

class Neo4jDatabase implements DatabaseInterface
{
    private const int RESTORE_BATCH_BYTES = 1024 * 1024;

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
                $this->writeAll($file, $record->get('cypherStatements'), $outputPath);
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
        $client = $this->createClient();
        $this->restoreCypherFile($client, $inputPath);

        return new DatabaseOperationResult(
            command: null,
            log: new DatabaseOperationLog('Neo4j APOC restore completed', 'info'),
        );
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        $logger->log('Preparing Neo4j database for restore', 'info', [
            'database' => $schemaName,
            'force_database' => $forceDatabase,
        ]);

        $client = $this->createClient();

        $client->writeTransaction(
            function ($tsx): void {
                $tsx->run('CALL apoc.schema.assert({}, {})');
                $tsx->run('MATCH (n) DETACH DELETE n');
            }
        );

        $logger->log('Neo4j database cleared for restore', 'info', [
            'database' => $schemaName,
        ]);
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
        $config = $this->validatedConfig();

        return ClientBuilder::create()
            ->withDriver(
                'default',
                sprintf('bolt://%s:%d', $config['host'], $config['port']),
                Authenticate::basic($config['user'], $config['pass'])
            )
            ->withDefaultDriverConfiguration(
                DriverConfiguration::default()
                    ->withSocketType(SocketType::STREAM())
                    ->withAcquireConnectionTimeout(10.0)
            )
            ->withDefaultSessionConfiguration(
                SessionConfiguration::default()->withDatabase($config['database'])
            )
            ->build();
    }

    /**
     * @param  resource  $file
     */
    private function writeAll($file, string $contents, string $outputPath): void
    {
        $expectedBytes = strlen($contents);
        $writtenBytes = 0;

        while ($writtenBytes < $expectedBytes) {
            $written = fwrite($file, substr($contents, $writtenBytes));
            if ($written === false || $written === 0) {
                throw new \RuntimeException(
                    "Failed to write Neo4j dump to {$outputPath}: wrote {$writtenBytes} of {$expectedBytes} bytes"
                );
            }

            $writtenBytes += $written;
        }
    }

    private function restoreCypherFile(ClientInterface $client, string $inputPath): void
    {
        $file = fopen($inputPath, 'r');
        if ($file === false) {
            throw new \RuntimeException("Failed to read restore file: {$inputPath}");
        }

        try {
            $batch = '';
            foreach ($this->readCypherStatements($file) as $statement) {
                if (strlen($batch) + strlen($statement) > self::RESTORE_BATCH_BYTES && $batch !== '') {
                    $this->runCypherBatch($client, $batch);
                    $batch = '';
                }

                $batch .= $statement;
            }

            if ($batch !== '') {
                $this->runCypherBatch($client, $batch);
            }
        } finally {
            fclose($file);
        }
    }

    /**
     * @param  resource  $file
     * @return \Generator<int, string>
     */
    private function readCypherStatements($file): \Generator
    {
        $statement = '';
        $quote = null;
        $escaped = false;

        while (($chunk = fread($file, 8192)) !== false && $chunk !== '') {
            $length = strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];
                $statement .= $char;

                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\' && ($quote === '\'' || $quote === '"')) {
                    $escaped = true;

                    continue;
                }

                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($char === '\'' || $char === '"' || $char === '`') {
                    $quote = $char;

                    continue;
                }

                if ($char === ';') {
                    yield $statement;
                    $statement = '';
                }
            }
        }

        if ($chunk === false) {
            throw new \RuntimeException('Failed while reading Neo4j restore file');
        }

        if (trim($statement) !== '') {
            yield $statement;
        }
    }

    private function runCypherBatch(ClientInterface $client, string $cypher): void
    {
        $client->writeTransaction(
            function ($tsx) use ($cypher): void {
                $tsx->run('CALL apoc.cypher.runMany($cypher, {})', ['cypher' => $cypher]);
            }
        );
    }

    /**
     * @return array{host: string, port: int, user: string, pass: string, database: string}
     */
    private function validatedConfig(): array
    {
        $required = ['host', 'port', 'user', 'pass', 'database'];
        $missing = array_values(array_filter(
            $required,
            fn (string $key): bool => ! array_key_exists($key, $this->config) || $this->config[$key] === null || $this->config[$key] === ''
        ));

        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing required Neo4j configuration keys: '.implode(', ', $missing));
        }

        return [
            'host' => (string) $this->config['host'],
            'port' => (int) $this->config['port'],
            'user' => (string) $this->config['user'],
            'pass' => (string) $this->config['pass'],
            'database' => (string) $this->config['database'],
        ];
    }
}
