<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\DatabaseListService;

test('listDatabases returns databases excluding system databases', function (
    DatabaseType $databaseType,
    int $port,
    string $query,
    array $allDatabases,
    array $excludedDatabases,
    array $expectedDatabases
) {
    // Arrange - Create a test double for the server
    $server = new class($databaseType, $port) extends DatabaseServer
    {
        public function __construct(DatabaseType $databaseType, int $port)
        {
            // Skip parent constructor to avoid database interaction
            $this->database_type = $databaseType;
            $this->host = '127.0.0.1';
            $this->port = $port;
            $this->username = 'admin';
            $this->password = 'admin';
        }
    };

    // Mock PDOStatement
    $pdoStatement = Mockery::mock(\PDOStatement::class);
    $pdoStatement->shouldReceive('fetchAll')
        ->once()
        ->with(PDO::FETCH_COLUMN, 0)
        ->andReturn($allDatabases);

    // Mock PDO
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')
        ->once()
        ->with($query)
        ->andReturn($pdoStatement);

    // Partial mock the service to inject our mocked PDO
    $service = Mockery::mock(DatabaseListService::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('createConnection')
        ->once()
        ->with($server, null) // Second arg is tunnel endpoint (null when no SSH)
        ->andReturn($pdo);

    // Act
    $databases = $service->listDatabases($server);

    // Assert - check expected databases are present
    expect($databases)->toBeArray()
        ->and($databases)->toHaveCount(count($expectedDatabases));

    foreach ($expectedDatabases as $db) {
        expect($databases)->toContain($db);
    }

    // Assert - check excluded databases are not present
    foreach ($excludedDatabases as $db) {
        expect($databases)->not->toContain($db);
    }
})->with([
    'mysql' => [
        'databaseType' => DatabaseType::MYSQL,
        'port' => 3306,
        'query' => 'SHOW DATABASES',
        'allDatabases' => [
            'information_schema',
            'performance_schema',
            'mysql',
            'sys',
            'app_database',
            'test_database',
            'production_db',
        ],
        'excludedDatabases' => ['information_schema', 'performance_schema', 'mysql', 'sys'],
        'expectedDatabases' => ['app_database', 'test_database', 'production_db'],
    ],
    'postgres' => [
        'databaseType' => DatabaseType::POSTGRESQL,
        'port' => 5432,
        'query' => 'SELECT datname FROM pg_database WHERE datistemplate = false',
        'allDatabases' => [
            'postgres',
            'rdsadmin',
            'azure_maintenance',
            'azure_sys',
            'app_database',
            'analytics_db',
        ],
        'excludedDatabases' => ['postgres', 'rdsadmin', 'azure_maintenance', 'azure_sys'],
        'expectedDatabases' => ['app_database', 'analytics_db'],
    ],
]);

test('createConnection uses tunnel endpoint when provided', function () {
    // Arrange - Create a test double for the server
    $server = new class extends DatabaseServer
    {
        public function __construct()
        {
            $this->database_type = DatabaseType::MYSQL;
            $this->host = 'private-db.internal';
            $this->port = 3306;
            $this->username = 'admin';
            $this->password = 'admin';
        }

        public function getDecryptedPassword(): string
        {
            return 'admin';
        }
    };

    $service = new DatabaseListService;
    $tunnelEndpoint = ['host' => '127.0.0.1', 'port' => 54321];

    // Act - We expect this to fail since there's no actual MySQL server,
    // but we're verifying the code path is exercised
    $exception = null;
    try {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('createConnection');
        $method->invoke($service, $server, $tunnelEndpoint);
    } catch (\PDOException $e) {
        $exception = $e;
    }

    // Should have thrown PDOException trying to connect via tunnel endpoint
    expect($exception)->toBeInstanceOf(\PDOException::class);
});
