<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Services\Backup\DTO\DatabaseOperationResult;
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
        // Everything (host, port, credentials, advanced options) is carried by
        // the connection URI. `--uri` is mutually exclusive with `--db`, so the
        // dump is scoped by putting the database in the URI path.
        $parts = [
            'mongodump',
            '--uri='.escapeshellarg($this->connectionUri($this->config['database'])),
        ];

        if (! empty($this->config['dump_flags'])) {
            $parts[] = DatabaseOperationResult::escapeFlags($this->config['dump_flags']);
        }

        $parts[] = '--archive='.escapeshellarg($outputPath);

        return new DatabaseOperationResult(command: implode(' ', $parts));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $sourceDb = $this->config['source_database'] ?? $this->config['database'];
        $targetDb = $this->config['database'];

        $parts = [
            'mongorestore',
            '--uri='.escapeshellarg($this->connectionUri()),
            '--archive='.escapeshellarg($inputPath),
            '--nsFrom='.escapeshellarg("{$sourceDb}.*"),
            '--nsTo='.escapeshellarg("{$targetDb}.*"),
            '--drop',
        ];

        return new DatabaseOperationResult(command: implode(' ', $parts));
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
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
     * Build a MongoDB connection URI.
     *
     * The base form is `mongodb://user:pass@host:port/?authSource=X`
     * (authenticated) or `mongodb://host:port` (anonymous). Advanced options
     * extend it: SRV switches the scheme (and drops the port), and
     * `connection_options` is appended verbatim — that is where TLS, replica
     * set and any other connection-string parameters go (e.g. `tls=true`,
     * `replicaSet=rs0`).
     *
     * A non-empty `database` is written as the URI path. `mongodump` cannot
     * combine `--uri` with `--db`, so the dump scopes to a single database this
     * way; driver connections and `mongorestore` pass no database here.
     *
     * @param  array{auth_source?: string, srv?: bool, connection_options?: string, database?: string}  $options
     */
    public static function buildConnectionUri(string $host, ?int $port, string $user = '', string $pass = '', array $options = []): string
    {
        $srv = ! empty($options['srv']);
        $scheme = $srv ? 'mongodb+srv' : 'mongodb';
        $hostPart = $srv ? $host : sprintf('%s:%d', $host, (int) $port);

        $hasCredentials = ! empty($user) && ! empty($pass);
        $credentials = $hasCredentials
            ? rawurlencode($user).':'.rawurlencode($pass).'@'
            : '';

        // authSource is only meaningful when authenticating, matching the
        // historical behaviour of the anonymous URI carrying no query string.
        $params = [];
        if ($hasCredentials) {
            $params[] = 'authSource='.rawurlencode($options['auth_source'] ?? 'admin');
        }
        if (! empty($options['connection_options'])) {
            $params[] = ltrim($options['connection_options'], '?&');
        }

        $path = ! empty($options['database']) ? '/'.rawurlencode($options['database']) : '';

        $uri = sprintf('%s://%s%s', $scheme, $credentials, $hostPart);

        if ($params !== []) {
            // A slash is required before the query string; the database path
            // provides it when present, otherwise fall back to a bare slash.
            $uri .= ($path !== '' ? $path : '/').'?'.implode('&', $params);
        } else {
            $uri .= $path;
        }

        return $uri;
    }

    private function authSource(): string
    {
        return $this->config['auth_source'] ?? 'admin';
    }

    /**
     * Build the connection URI from the current config (driver + CLI `--uri`).
     *
     * @param  string|null  $database  Scopes the URI to a single database (dump only)
     */
    private function connectionUri(?string $database = null): string
    {
        return self::buildConnectionUri(
            $this->config['host'],
            isset($this->config['port']) ? (int) $this->config['port'] : null,
            $this->config['user'] ?? '',
            $this->config['pass'] ?? '',
            [
                'auth_source' => $this->authSource(),
                'srv' => ! empty($this->config['srv']),
                'connection_options' => $this->config['connection_options'] ?? '',
                'database' => $database ?? '',
            ],
        );
    }

    /**
     * Create MongoDB Manager instance.
     * Note: No explicit return type due to Manager being final and unmockable in tests.
     *
     * @return Manager
     */
    protected function createManager()
    {
        $uri = $this->connectionUri();

        $timeoutMs = (int) ($this->config['connect_timeout'] ?? 10) * 1000;

        return new Manager($uri, [
            'connectTimeoutMS' => $timeoutMs,
            'serverSelectionTimeoutMS' => $timeoutMs,
        ]);
    }
}
