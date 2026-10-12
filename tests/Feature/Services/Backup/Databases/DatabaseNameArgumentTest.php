<?php

use App\Exceptions\Backup\BackupException;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\FirebirdDatabase;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Databases\SqliteDatabase;

test('a database name starting with a dash is refused when building a command', function (DatabaseInterface $db, string $operation) {
    $db->setConfig([
        'host' => '',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => '-app',
        'sqlite_path' => '-app',
        'dump_format' => $operation === 'restore-custom' ? 'custom' : 'plain',
    ]);

    $operation === 'dump' ? $db->dump('/tmp/out') : $db->restore('/tmp/in');
})->with([
    'mysql dump' => [fn () => new MysqlDatabase, 'dump'],
    'mysql restore' => [fn () => new MysqlDatabase, 'restore'],
    'postgres dump' => [fn () => new PostgresqlDatabase, 'dump'],
    'postgres restore' => [fn () => new PostgresqlDatabase, 'restore'],
    'postgres custom restore' => [fn () => new PostgresqlDatabase, 'restore-custom'],
    'sqlite dump' => [fn () => new SqliteDatabase, 'dump'],
    'firebird dump' => [fn () => new FirebirdDatabase, 'dump'],
    'firebird restore' => [fn () => new FirebirdDatabase, 'restore'],
])->throws(BackupException::class, 'must not start with a dash');
