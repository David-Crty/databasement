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
