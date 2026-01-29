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
        ->with($server)
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
