<?php

use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\Databases\RedisDatabase;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->db = new RedisDatabase;
    $this->db->setConfig([
        'host' => 'redis.example.com',
        'port' => 6379,
        'user' => '',
        'pass' => '',
    ]);
});

test('getDumpCommandLine produces redis-cli rdb command', function () {
    expect($this->db->getDumpCommandLine('/tmp/dump.rdb'))
        ->toBe("redis-cli -h 'redis.example.com' -p '6379' --no-auth-warning --rdb '/tmp/dump.rdb'");
});

test('getDumpCommandLine includes auth flags when credentials provided', function () {
    $db = new RedisDatabase;
    $db->setConfig([
        'host' => 'redis.example.com',
        'port' => 6379,
        'user' => 'myuser',
        'pass' => 'secret',
    ]);

    expect($db->getDumpCommandLine('/tmp/dump.rdb'))
        ->toBe("redis-cli -h 'redis.example.com' -p '6379' --user 'myuser' -a 'secret' --no-auth-warning --rdb '/tmp/dump.rdb'");
});

test('getDumpCommandLine includes password only when no username', function () {
    $db = new RedisDatabase;
    $db->setConfig([
        'host' => 'redis.example.com',
        'port' => 6379,
        'user' => '',
        'pass' => 'secret',
    ]);

    expect($db->getDumpCommandLine('/tmp/dump.rdb'))
        ->toBe("redis-cli -h 'redis.example.com' -p '6379' -a 'secret' --no-auth-warning --rdb '/tmp/dump.rdb'");
});

test('getRestoreCommandLine throws unsupported exception', function () {
    expect(fn () => $this->db->getRestoreCommandLine('/tmp/dump.rdb'))
        ->toThrow(UnsupportedDatabaseTypeException::class);
});

test('prepareForRestore throws unsupported exception', function () {
    $job = Mockery::mock(\App\Models\BackupJob::class);

    expect(fn () => $this->db->prepareForRestore('all', $job))
        ->toThrow(UnsupportedDatabaseTypeException::class);
});

test('testConnection returns success with server info', function () {
    Process::fake([
        '*PING' => Process::result(output: 'PONG'),
        '*INFO*' => Process::result(output: "redis_version:7.2.4\nused_memory_human:1.5M\nos:Linux 6.1"),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toContain('Redis 7.2.4');
});

test('testConnection returns failure when process fails', function () {
    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'Could not connect to Redis'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Could not connect to Redis');
});

test('testConnection returns failure when ping response is unexpected', function () {
    Process::fake([
        '*' => Process::result(output: 'LOADING Redis is loading the dataset in memory'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Unexpected response from Redis server');
});
