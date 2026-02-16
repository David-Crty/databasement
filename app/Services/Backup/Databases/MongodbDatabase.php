<?php

namespace App\Services\Backup\Databases;

use App\Models\BackupJob;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\Exception as MongoException;
use MongoDB\Driver\Manager;

class MongodbDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const array EXCLUDED_DATABASES = [
        'admin',
        'local',
        'config',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $parts = [
            'mongodump',
            ...$this->buildBaseArgs(),
            ...$this->buildAuthFlags(),
            '--db='.escapeshellarg($this->config['database']),
            '--archive='.escapeshellarg($outputPath),
        ];

        return new DatabaseOperationResult(command: implode(' ', $parts));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $sourceDb = $this->config['source_database'] ?? $this->config['database'];
        $targetDb = $this->config['database'];

        $parts = [
            'mongorestore',
            ...$this->buildBaseArgs(),
            ...$this->buildAuthFlags(),
            '--archive='.escapeshellarg($inputPath),
            '--nsFrom='.escapeshellarg("{$sourceDb}.*"),
            '--nsTo='.escapeshellarg("{$targetDb}.*"),
            '--drop',
        ];

        return new DatabaseOperationResult(command: implode(' ', $parts));
    }

    public function prepareForRestore(string $schemaName, BackupJob $job): void
    {
        // MongoDB restore uses --drop flag to handle existing collections; no separate preparation needed
    }

    public function listDatabases(): array
    {
        $manager = $this->createManager();
        $cursor = $manager->executeCommand('admin', new Command(['listDatabases' => 1]));
        $response = $cursor->toArray()[0];

        /** @var array<object{name: string}> $dbList */
        $dbList = $response->databases;

        $databases = array_map(
            fn (object $db): string => $db->name,
            $dbList,
        );

        return array_values(array_filter($databases, fn (string $db): bool => ! in_array($db, self::EXCLUDED_DATABASES)));
    }

    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $manager = $this->createManager();
            $authDb = $this->authSource();
            $cursor = $manager->executeCommand($authDb, new Command(['ping' => 1]));
            $response = $cursor->toArray()[0];
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if (! isset($response->ok) || (int) $response->ok !== 1) {
                return ['success' => false, 'message' => 'Unexpected response from MongoDB server', 'details' => []];
            }

            $serverInfo = ['dbms' => 'MongoDB'];

            try {
                $infoCursor = $manager->executeCommand($authDb, new Command(['buildInfo' => 1]));
                $info = $infoCursor->toArray()[0];
                if (isset($info->version)) {
                    $serverInfo['dbms'] = 'MongoDB '.$info->version;
                    $serverInfo['version'] = $info->version;
                }
            } catch (MongoException) {
                // Non-critical
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'ping_ms' => $durationMs,
                    'output' => json_encode($serverInfo, JSON_PRETTY_PRINT),
                ],
            ];
        } catch (MongoException $e) {
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

    /**
     * Build the base host/port arguments.
     *
     * @return array<string>
     */
    private function buildBaseArgs(): array
    {
        return [
            '--host='.escapeshellarg($this->config['host']),
            '--port='.escapeshellarg((string) $this->config['port']),
        ];
    }

    /**
     * Build authentication flags for MongoDB CLI tools.
     *
     * @return array<string>
     */
    private function buildAuthFlags(): array
    {
        $user = $this->config['user'] ?? '';
        $pass = $this->config['pass'] ?? '';

        if (empty($user) || empty($pass)) {
            return [];
        }

        return [
            '--username='.escapeshellarg($user),
            '--password='.escapeshellarg($pass),
            '--authenticationDatabase='.escapeshellarg($this->authSource()),
        ];
    }

    /**
     * Build a MongoDB connection URI.
     */
    public static function buildConnectionUri(string $host, int $port, string $user = '', string $pass = '', string $authSource = 'admin'): string
    {
        if (! empty($user) && ! empty($pass)) {
            return sprintf(
                'mongodb://%s:%s@%s:%d/?authSource=%s',
                rawurlencode($user),
                rawurlencode($pass),
                $host,
                $port,
                rawurlencode($authSource),
            );
        }

        return sprintf('mongodb://%s:%d', $host, $port);
    }

    private function authSource(): string
    {
        return $this->config['auth_source'] ?? 'admin';
    }

    /**
     * Create MongoDB Manager instance.
     * Note: No explicit return type due to Manager being final and unmockable in tests.
     *
     * @return Manager
     */
    protected function createManager()
    {
        $uri = self::buildConnectionUri(
            $this->config['host'],
            (int) $this->config['port'],
            $this->config['user'] ?? '',
            $this->config['pass'] ?? '',
            $this->authSource(),
        );

        return new Manager($uri, [
            'connectTimeoutMS' => 10000,
            'serverSelectionTimeoutMS' => 10000,
        ]);
    }
}
