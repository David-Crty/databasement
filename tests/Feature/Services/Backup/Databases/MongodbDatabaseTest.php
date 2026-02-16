<?php

use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Services\Backup\Databases\MongodbDatabase;
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

test('dump produces mongodump archive command', function () {
    $result = $this->db->dump('/tmp/dump.archive');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mongodump --host='mongo.example.com' --port='27017' --db='mydb' --archive='/tmp/dump.archive'");
});

test('dump includes auth flags when credentials provided', function () {
    $this->db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => 'admin',
        'pass' => 'secret',
        'database' => 'mydb',
        'auth_source' => 'admin',
    ]);

    $result = $this->db->dump('/tmp/dump.archive');

    expect($result->command)->toBe("mongodump --host='mongo.example.com' --port='27017' --username='admin' --password='secret' --authenticationDatabase='admin' --db='mydb' --archive='/tmp/dump.archive'");
});

test('dump uses custom auth_source', function () {
    $this->db->setConfig([
        'host' => 'mongo.example.com',
        'port' => 27017,
        'user' => 'appuser',
        'pass' => 'secret',
        'database' => 'mydb',
        'auth_source' => 'myAuthDb',
    ]);

    $result = $this->db->dump('/tmp/dump.archive');

    expect($result->command)->toContain("--authenticationDatabase='myAuthDb'");
});

test('restore produces mongorestore command with namespace mapping', function () {
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
        ->and($result->command)->toBe("mongorestore --host='mongo.example.com' --port='27017' --username='admin' --password='secret' --authenticationDatabase='admin' --archive='/tmp/dump.archive' --nsFrom='sourcedb.*' --nsTo='targetdb.*' --drop");
});

test('restore uses same database for nsFrom when source_database not set', function () {
    $result = $this->db->restore('/tmp/dump.archive');

    expect($result->command)->toContain("--nsFrom='mydb.*'")
        ->and($result->command)->toContain("--nsTo='mydb.*'");
});

test('prepareForRestore is a no-op', function () {
    $job = Mockery::mock(\App\Models\BackupJob::class);

    expect(fn () => $this->db->prepareForRestore('mydb', $job))->not->toThrow(Exception::class);
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
