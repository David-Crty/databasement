<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

it('succeeds when database connection works', function (): void {
    artisan('db:wait')
        ->assertExitCode(0);
});

it('succeeds with --allow-missing-db when MySQL database does not exist', function (): void {
    DB::shouldReceive('connection->getPdo')
        ->andThrow(new \Exception('SQLSTATE[HY000] [1049] Unknown database \'missing_db\''));
    DB::shouldReceive('connection->getDriverName')
        ->andReturn('mysql');

    artisan('db:wait', ['--allow-missing-db' => true])
        ->assertExitCode(0);
});

it('succeeds with --allow-missing-db when PostgreSQL database does not exist', function (): void {
    DB::shouldReceive('connection->getPdo')
        ->andThrow(new \Exception('SQLSTATE[08006] [7] connection to server failed: FATAL:  database "missing_db" does not exist'));
    DB::shouldReceive('connection->getDriverName')
        ->andReturn('pgsql');

    artisan('db:wait', ['--allow-missing-db' => true])
        ->assertExitCode(0);
});

it('succeeds with --allow-missing-db when SQLite database file is missing', function (): void {
    DB::shouldReceive('connection->getPdo')
        ->andThrow(new \Exception('Database file at path [/data/database.sqlite] does not exist.'));
    DB::shouldReceive('connection->getDriverName')
        ->andReturn('sqlite');

    artisan('db:wait', ['--allow-missing-db' => true])
        ->assertExitCode(0);
});

it('succeeds with --check-migrations when all migrations have been run', function (): void {
    artisan('db:wait', ['--check-migrations' => true])
        ->assertExitCode(0);
});
