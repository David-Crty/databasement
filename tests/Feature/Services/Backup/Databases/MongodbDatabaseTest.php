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

test('dump produces mongodump --uri archive command', function () {
    $result = $this->db->dump('/tmp/dump.archive');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mongodump --uri='mongodb://mongo.example.com:27017/mydb' --archive='/tmp/dump.archive'");
});

test('dump embeds credentials in the uri', function () {
    $this->db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => 'admin',
        'pass' => 'secret',
        'database' => 'mydb',
        'auth_source' => 'admin',
    ]);

    $result = $this->db->dump('/tmp/dump.archive');

    expect($result->command)->toBe("mongodump --uri='mongodb://admin:secret@mongo.example.com:27017/mydb?authSource=admin' --archive='/tmp/dump.archive'");
});

test('dump uses custom auth_source in the uri', function () {
    $this->db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => 'appuser',
        'pass' => 'secret',
        'database' => 'mydb',
        'auth_source' => 'myAuthDb',
    ]);

    $result = $this->db->dump('/tmp/dump.archive');

    expect($result->command)->toContain('authSource=myAuthDb');
});

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

test('buildConnectionUri preserves legacy authenticated output', function () {
    $uri = MongodbDatabase::buildConnectionUri('host', 27017, 'user', 'secret', ['auth_source' => 'admin']);

    expect($uri)->toBe('mongodb://user:secret@host:27017/?authSource=admin');
});

test('buildConnectionUri preserves legacy anonymous output', function () {
    $uri = MongodbDatabase::buildConnectionUri('host', 27017);

    expect($uri)->toBe('mongodb://host:27017');
});

test('buildConnectionUri url-encodes credentials', function () {
    $uri = MongodbDatabase::buildConnectionUri('host', 27017, 'u@ser', 'p:ss/word', ['auth_source' => 'admin']);

    expect($uri)->toBe('mongodb://u%40ser:p%3Ass%2Fword@host:27017/?authSource=admin');
});

test('buildConnectionUri uses SRV scheme and omits port', function () {
    $uri = MongodbDatabase::buildConnectionUri('cluster.example.mongodb.net', null, 'user', 'secret', [
        'auth_source' => 'admin',
        'srv' => true,
    ]);

    expect($uri)->toBe('mongodb+srv://user:secret@cluster.example.mongodb.net/?authSource=admin');
});

test('buildConnectionUri appends tls, replica set and connection options', function () {
    $uri = MongodbDatabase::buildConnectionUri('host', 27017, 'user', 'secret', [
        'auth_source' => 'admin',
        'tls' => true,
        'replica_set' => 'rs0',
        'connection_options' => 'retryWrites=true&w=majority',
    ]);

    expect($uri)->toBe('mongodb://user:secret@host:27017/?authSource=admin&tls=true&replicaSet=rs0&retryWrites=true&w=majority');
});

test('buildConnectionUri includes options without credentials', function () {
    $uri = MongodbDatabase::buildConnectionUri('host', 27017, '', '', ['tls' => true]);

    expect($uri)->toBe('mongodb://host:27017/?tls=true');
});

test('dump builds an SRV uri', function () {
    $this->db->setConfig([
        'host' => 'cluster.example.mongodb.net',
        'port' => null,
        'user' => 'user',
        'pass' => 'secret',
        'database' => 'mydb',
        'auth_source' => 'admin',
        'srv' => true,
    ]);

    $result = $this->db->dump('/tmp/dump.archive');

    expect($result->command)->toBe("mongodump --uri='mongodb+srv://user:secret@cluster.example.mongodb.net/mydb?authSource=admin' --archive='/tmp/dump.archive'");
});

test('restore builds a tls uri', function () {
    $this->db->setConfig([
        'host' => 'host',
        'port' => 27017,
        'user' => 'user',
        'pass' => 'secret',
        'database' => 'targetdb',
        'auth_source' => 'admin',
        'source_database' => 'sourcedb',
        'tls' => true,
    ]);

    $result = $this->db->restore('/tmp/dump.archive');

    expect($result->command)->toBe("mongorestore --uri='mongodb://user:secret@host:27017/?authSource=admin&tls=true' --archive='/tmp/dump.archive' --nsFrom='sourcedb.*' --nsTo='targetdb.*' --drop");
});

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
