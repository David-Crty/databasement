<?php

use App\Services\Backup\Databases\MongodbDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use MongoDB\Driver\Exception\ConnectionTimeoutException;

beforeEach(function () {
    $this->db = new MongodbDatabase;
    $this->db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => '',
        'pass' => '',
        'database' => 'mydb',
        'auth_source' => 'admin',
    ]);
});

function mockMongodbWithManager(object $manager): MongodbDatabase
{
    $db = Mockery::mock(MongodbDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createManager')->andReturn($manager);
    $db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'mydb',
        'auth_source' => 'admin',
    ]);

    return $db;
}

function fakeCursor(object $response): object
{
    return new class($response)
    {
        public function __construct(private readonly object $response) {}

        /** @return array<object> */
        public function toArray(): array
        {
            return [$this->response];
        }
    };
}

test('dump builds the mongodump --uri command scoping the database in the path', function (array $config, string $expectedUri) {
    $this->db->setConfig($config);

    $result = $this->db->dump('/tmp/dump.archive');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mongodump --uri='{$expectedUri}' --archive='/tmp/dump.archive'");
})->with([
    'anonymous' => [
        ['host' => 'mongo.example.com', 'port' => 27017, 'user' => '', 'pass' => '', 'database' => 'mydb', 'auth_source' => 'admin'],
        'mongodb://mongo.example.com:27017/mydb',
    ],
    'credentials' => [
        ['host' => 'mongo.example.com', 'port' => 27017, 'user' => 'admin', 'pass' => 'secret', 'database' => 'mydb', 'auth_source' => 'admin'],
        'mongodb://admin:secret@mongo.example.com:27017/mydb?authSource=admin',
    ],
    'srv scheme' => [
        ['host' => 'cluster.example.mongodb.net', 'port' => null, 'user' => 'user', 'pass' => 'secret', 'database' => 'mydb', 'auth_source' => 'admin', 'srv' => true],
        'mongodb+srv://user:secret@cluster.example.mongodb.net/mydb?authSource=admin',
    ],
    'connection options' => [
        ['host' => 'host', 'port' => 27017, 'user' => 'user', 'pass' => 'secret', 'database' => 'mydb', 'auth_source' => 'admin', 'connection_options' => 'tls=true'],
        'mongodb://user:secret@host:27017/mydb?authSource=admin&tls=true',
    ],
]);

test('restore produces mongorestore --uri command with namespace mapping', function () {
    $this->db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => 'admin',
        'pass' => 'secret',
        'database' => 'targetdb',
        'auth_source' => 'admin',
        'source_database' => 'sourcedb',
    ]);

    $result = $this->db->restore('/tmp/dump.archive');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mongorestore --uri='mongodb://admin:secret@mongo.example.com:27017/?authSource=admin' --archive='/tmp/dump.archive' --nsFrom='sourcedb.*' --nsTo='targetdb.*' --drop");
});

test('restore uses same database for nsFrom when source_database not set', function () {
    $result = $this->db->restore('/tmp/dump.archive');

    expect($result->command)->toContain("--nsFrom='mydb.*'")
        ->and($result->command)->toContain("--nsTo='mydb.*'");
});

test('buildConnectionUri builds the expected uri', function (?int $port, string $user, string $pass, array $options, string $expected) {
    expect(MongodbDatabase::buildConnectionUri('host', $port, $user, $pass, $options))->toBe($expected);
})->with([
    'legacy authenticated output' => [27017, 'user', 'secret', ['auth_source' => 'admin'], 'mongodb://user:secret@host:27017/?authSource=admin'],
    'custom auth source' => [27017, 'appuser', 'secret', ['auth_source' => 'myAuthDb'], 'mongodb://appuser:secret@host:27017/?authSource=myAuthDb'],
    'auth source defaults to admin' => [27017, 'user', 'secret', [], 'mongodb://user:secret@host:27017/?authSource=admin'],
    'legacy anonymous output' => [27017, '', '', [], 'mongodb://host:27017'],
    'url-encoded credentials' => [27017, 'u@ser', 'p:ss/word', ['auth_source' => 'admin'], 'mongodb://u%40ser:p%3Ass%2Fword@host:27017/?authSource=admin'],
    'srv scheme omits port' => [null, 'user', 'secret', ['auth_source' => 'admin', 'srv' => true], 'mongodb+srv://user:secret@host/?authSource=admin'],
    'connection options appended verbatim' => [27017, 'user', 'secret', ['auth_source' => 'admin', 'connection_options' => 'tls=true&replicaSet=rs0&retryWrites=true&w=majority'], 'mongodb://user:secret@host:27017/?authSource=admin&tls=true&replicaSet=rs0&retryWrites=true&w=majority'],
    'options without credentials' => [27017, '', '', ['connection_options' => 'tls=true'], 'mongodb://host:27017/?tls=true'],
]);

test('prepareForRestore is a no-op', function () {
    $logger = Mockery::mock(\App\Contracts\BackupLogger::class);

    expect(fn () => $this->db->prepareForRestore('mydb', $logger))->not->toThrow(Exception::class);
});

test('listDatabases returns databases excluding system databases', function () {
    $manager = Mockery::mock();
    $manager->shouldReceive('executeCommand')
        ->once()
        ->withArgs(fn (string $db) => $db === 'admin')
        ->andReturn(fakeCursor((object) [
            'databases' => [
                (object) ['name' => 'admin'],
                (object) ['name' => 'local'],
                (object) ['name' => 'config'],
                (object) ['name' => 'app_db'],
                (object) ['name' => 'analytics'],
            ],
        ]));

    $db = mockMongodbWithManager($manager);

    expect($db->listDatabases())->toBe(['app_db', 'analytics']);
});

test('testConnection returns success with version info', function () {
    $manager = Mockery::mock();
    $manager->shouldReceive('executeCommand')
        ->twice()
        ->andReturn(
            fakeCursor((object) ['ok' => 1]),
            fakeCursor((object) ['version' => '8.0.4']),
        );

    $db = mockMongodbWithManager($manager);
    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toContain('MongoDB 8.0.4');
});

test('testConnection returns failure on connection error', function () {
    $manager = Mockery::mock();
    $manager->shouldReceive('executeCommand')
        ->andThrow(new ConnectionTimeoutException('No suitable servers found'));

    $db = mockMongodbWithManager($manager);
    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('No suitable servers found');
});
