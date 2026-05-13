<?php

use App\Services\Backup\Databases\Neo4jDatabase;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Types\CypherMap;

function mockNeo4jWithClient(ClientInterface $client): Neo4jDatabase
{
    $db = Mockery::mock(Neo4jDatabase::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createClient')->andReturn($client);
    $db->setConfig([
        'host' => 'neo4j.example.com',
        'port' => 7687,
        'user' => 'neo4j',
        'pass' => 'secret',
        'database' => 'movies',
    ]);

    return $db;
}

/**
 * @param  array<array<string, mixed>>  $rows
 */
function fakeNeo4jResult(array $rows): SummarizedResult
{
    $summary = null;
    $maps = array_map(fn (array $row) => new CypherMap($row), $rows);

    return new SummarizedResult($summary, $maps);
}

beforeEach(function () {
    $this->db = new Neo4jDatabase;
    $this->db->setConfig([
        'host' => 'neo4j.example.com',
        'port' => 7687,
        'user' => 'neo4j',
        'pass' => 'secret',
        'database' => 'movies',
    ]);
});

test('testConnection returns success with version info', function () {
    $client = Mockery::mock(ClientInterface::class);

    $client->shouldReceive('run')
        ->with('RETURN 1 AS ping')
        ->once()
        ->andReturn(fakeNeo4jResult([['ping' => 1]]));

    $client->shouldReceive('run')
        ->with('CALL dbms.components() YIELD name, versions')
        ->once()
        ->andReturn(fakeNeo4jResult([
            ['name' => 'Neo4j Kernel', 'versions' => ['5.26.0']],
        ]));

    $db = mockNeo4jWithClient($client);
    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['ping_ms'])->toBeInt()
        ->and($result['details']['output'])->toContain('Neo4j 5.26.0');
});

test('testConnection returns failure on connection error', function () {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('run')
        ->with('RETURN 1 AS ping')
        ->once()
        ->andThrow(new \RuntimeException('Connection refused'));

    $db = mockNeo4jWithClient($client);
    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Connection refused')
        ->and($result['details'])->toBeEmpty();
});

test('listDatabases returns user databases excluding system', function () {
    $client = Mockery::mock(ClientInterface::class);

    $client->shouldReceive('run')
        ->with('SHOW DATABASES YIELD name WHERE name <> "system" RETURN name')
        ->once()
        ->andReturn(fakeNeo4jResult([
            ['name' => 'neo4j'],
            ['name' => 'movies'],
        ]));

    $db = mockNeo4jWithClient($client);
    $databases = $db->listDatabases();

    expect($databases)->toBe(['neo4j', 'movies']);
});

test('dump calls APOC export and writes cypherStatements to output file', function () {
    $client = Mockery::mock(ClientInterface::class);
    $tsx = Mockery::mock(\Laudis\Neo4j\Contracts\TransactionInterface::class);

    $tsx->shouldReceive('run')
        ->once()
        ->with(Mockery::pattern('/apoc\.export\.cypher\.all/'))
        ->andReturn(fakeNeo4jResult([
            ['cypherStatements' => 'CREATE (:Person {name: "Alice"});'],
        ]));

    $client->shouldReceive('readTransaction')
        ->once()
        ->andReturnUsing(function (callable $callback) use ($tsx) {
            return $callback($tsx);
        });

    $db = mockNeo4jWithClient($client);
    $outputPath = sys_get_temp_dir().'/neo4j_dump_test_'.uniqid().'.cypher';

    $result = $db->dump($outputPath);

    expect($result->command)->toBeNull()
        ->and($result->log->message)->toContain('export completed')
        ->and(file_get_contents($outputPath))->toBe('CREATE (:Person {name: "Alice"});');

    @unlink($outputPath);
});

test('dump uses UNWIND_BATCH optimisation in APOC call', function () {
    $client = Mockery::mock(ClientInterface::class);
    $tsx = Mockery::mock(\Laudis\Neo4j\Contracts\TransactionInterface::class);

    $capturedQuery = '';
    $tsx->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (string $query) use (&$capturedQuery) {
            $capturedQuery = $query;

            return fakeNeo4jResult([['cypherStatements' => '']]);
        });

    $client->shouldReceive('readTransaction')
        ->once()
        ->andReturnUsing(fn (callable $cb) => $cb($tsx));

    $db = mockNeo4jWithClient($client);
    $outputPath = sys_get_temp_dir().'/neo4j_dump_opt_'.uniqid().'.cypher';

    $db->dump($outputPath);

    expect($capturedQuery)->toContain('UNWIND_BATCH')
        ->and($capturedQuery)->toContain('cypherStatements');

    @unlink($outputPath);
});

test('prepareForRestore drops schema and deletes all nodes', function () {
    $client = Mockery::mock(ClientInterface::class);
    $tsx = Mockery::mock(\Laudis\Neo4j\Contracts\TransactionInterface::class);

    $schemaQuery = '';
    $deleteQuery = '';

    $tsx->shouldReceive('run')
        ->twice()
        ->andReturnUsing(function (string $query) use (&$schemaQuery, &$deleteQuery) {
            if (str_contains($query, 'apoc.schema.assert')) {
                $schemaQuery = $query;
            } else {
                $deleteQuery = $query;
            }

            return fakeNeo4jResult([]);
        });

    $client->shouldReceive('writeTransaction')
        ->once()
        ->andReturnUsing(function (callable $callback) use ($tsx) {
            $callback($tsx);
        });

    $db = mockNeo4jWithClient($client);
    $logger = Mockery::mock(\App\Contracts\BackupLogger::class);

    $db->prepareForRestore('movies', $logger);

    expect($schemaQuery)->toContain('apoc.schema.assert')
        ->and($deleteQuery)->toContain('DETACH DELETE');
});

test('restore runs apoc.cypher.runMany with dump file contents', function () {
    $client = Mockery::mock(ClientInterface::class);
    $tsx = Mockery::mock(\Laudis\Neo4j\Contracts\TransactionInterface::class);

    $cypher = 'CREATE (:Person {name: "Alice"});';
    $inputPath = sys_get_temp_dir().'/neo4j_restore_'.uniqid().'.cypher';
    file_put_contents($inputPath, $cypher);

    $capturedParams = [];
    $tsx->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (string $query, array $params) use (&$capturedParams) {
            $capturedParams = $params;

            return fakeNeo4jResult([]);
        });

    $client->shouldReceive('writeTransaction')
        ->once()
        ->andReturnUsing(function (callable $callback) use ($tsx) {
            $callback($tsx);
        });

    $db = mockNeo4jWithClient($client);
    $result = $db->restore($inputPath);

    expect($result->command)->toBeNull()
        ->and($result->log->message)->toContain('restore completed')
        ->and($capturedParams['cypher'])->toBe($cypher);

    @unlink($inputPath);
});

test('DatabaseProvider::make returns Neo4jDatabase for NEO4J type', function () {
    $provider = new \App\Services\Backup\Databases\DatabaseProvider;
    $db = $provider->make(\App\Enums\DatabaseType::NEO4J);

    expect($db)->toBeInstanceOf(\App\Services\Backup\Databases\Neo4jDatabase::class);
});

test('DatabaseProvider resolves connection database name as neo4j for Neo4j servers', function () {
    $server = \Database\Factories\DatabaseServerFactory::new()->neo4j()->make();

    $provider = new \App\Services\Backup\Databases\DatabaseProvider;
    $method = new \ReflectionMethod($provider, 'getConnectionDatabaseName');
    $method->setAccessible(true);

    expect($method->invoke($provider, $server))->toBe('neo4j');
});
