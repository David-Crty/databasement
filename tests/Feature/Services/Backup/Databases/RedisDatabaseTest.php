<?php

use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\Databases\RedisDatabase;

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
