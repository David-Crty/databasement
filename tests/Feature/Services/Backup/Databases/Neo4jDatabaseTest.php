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

test('testConnection returns timeout message after slow runtime error', function () {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('run')
        ->with('RETURN 1 AS ping')
        ->once()
        ->andThrow(new \RuntimeException('Connection timed out'));

    $db = mockNeo4jWithClient($client);
    $db->shouldReceive('elapsedMillisecondsSince')
        ->once()
        ->andReturn(9500);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Connection timed out after')
        ->and($result['message'])->toContain('Please check the host and port')
        ->and($result['details'])->toBeEmpty();
});

test('testConnection keeps success response when version lookup fails', function () {
    $client = Mockery::mock(ClientInterface::class);

    $client->shouldReceive('run')
        ->with('RETURN 1 AS ping')
        ->once()
        ->andReturn(fakeNeo4jResult([['ping' => 1]]));

    $client->shouldReceive('run')
        ->with('CALL dbms.components() YIELD name, versions')
        ->once()
        ->andThrow(new \RuntimeException('Procedure not available'));

    $db = mockNeo4jWithClient($client);
    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['output'])->toContain('Neo4j unknown');
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

test('dump throws when output file cannot be opened', function () {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldNotReceive('readTransaction');

    $db = mockNeo4jWithClient($client);

    $db->dump(sys_get_temp_dir().'/missing-directory-'.uniqid().'/dump.cypher');
})->throws(\RuntimeException::class, 'Failed to open output file for writing');

test('dump throws when writing to output stream fails', function () {
    $db = new Neo4jDatabase;
    $method = new ReflectionMethod($db, 'writeAll');
    $method->setAccessible(true);
    $readOnlyStream = fopen('php://memory', 'r');

    try {
        $method->invoke($db, $readOnlyStream, 'CREATE (:Person);', '/tmp/neo4j-readonly.cypher');
    } finally {
        fclose($readOnlyStream);
    }
})->throws(\RuntimeException::class, 'Failed to write Neo4j dump');

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
    $logger->shouldReceive('log')
        ->twice()
        ->with(Mockery::type('string'), 'info', Mockery::on(
            fn (array $context): bool => ($context['database'] ?? null) === 'movies'
        ));

    $db->prepareForRestore('movies', $logger);

    expect($schemaQuery)->toContain('apoc.schema.assert')
        ->and($deleteQuery)->toContain('DETACH DELETE');
});

test('createClient validates required configuration keys', function () {
    $db = new Neo4jDatabase;
    $db->setConfig([
        'port' => 7687,
        'user' => 'neo4j',
        'pass' => 'secret',
        'database' => 'movies',
    ]);

    $db->listDatabases();
})->throws(\InvalidArgumentException::class, 'Missing required Neo4j configuration keys: host');

test('createClient builds a Neo4j client from validated config', function () {
    $method = new ReflectionMethod($this->db, 'createClient');
    $method->setAccessible(true);

    expect($method->invoke($this->db))->toBeInstanceOf(ClientInterface::class);
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

test('restore throws when input file cannot be opened', function () {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldNotReceive('writeTransaction');

    $db = mockNeo4jWithClient($client);

    $db->restore(sys_get_temp_dir().'/missing_neo4j_restore_'.uniqid().'.cypher');
})->throws(\RuntimeException::class, 'Failed to read restore file');

test('restore batches large cypher files without splitting quoted semicolons', function () {
    $client = Mockery::mock(ClientInterface::class);
    $tsx = Mockery::mock(\Laudis\Neo4j\Contracts\TransactionInterface::class);

    $largeName = str_repeat('a', 600_000);
    $cypher = <<<CYPHER
CREATE (:Person {name: "{$largeName};one"});
CREATE (:Person {name: "{$largeName};two"});
CYPHER;
    $inputPath = sys_get_temp_dir().'/neo4j_restore_large_'.uniqid().'.cypher';
    file_put_contents($inputPath, $cypher);

    $batches = [];
    $tsx->shouldReceive('run')
        ->twice()
        ->andReturnUsing(function (string $query, array $params) use (&$batches) {
            $batches[] = $params['cypher'];

            return fakeNeo4jResult([]);
        });

    $client->shouldReceive('writeTransaction')
        ->twice()
        ->andReturnUsing(function (callable $callback) use ($tsx) {
            $callback($tsx);
        });

    $db = mockNeo4jWithClient($client);
    $db->restore($inputPath);

    expect($batches)->toHaveCount(2)
        ->and($batches[0])->toContain(';one"});')
        ->and($batches[1])->toContain(';two"});');

    @unlink($inputPath);
});

test('restore preserves escaped quotes and trailing statements without semicolons', function () {
    $client = Mockery::mock(ClientInterface::class);
    $tsx = Mockery::mock(\Laudis\Neo4j\Contracts\TransactionInterface::class);

    $cypher = <<<'CYPHER'
CREATE (:Person {name: "Alice \"The Ace\""});
CREATE (:Person {name: `Bob; Builder`})
CYPHER;
    $inputPath = sys_get_temp_dir().'/neo4j_restore_escaped_'.uniqid().'.cypher';
    file_put_contents($inputPath, $cypher);

    $batches = [];
    $tsx->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (string $query, array $params) use (&$batches) {
            $batches[] = $params['cypher'];

            return fakeNeo4jResult([]);
        });

    $client->shouldReceive('writeTransaction')
        ->once()
        ->andReturnUsing(fn (callable $callback) => $callback($tsx));

    $db = mockNeo4jWithClient($client);
    $db->restore($inputPath);

    expect($batches)->toHaveCount(1)
        ->and($batches[0])->toContain('Alice \"The Ace\"')
        ->and($batches[0])->toContain('`Bob; Builder`');

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
