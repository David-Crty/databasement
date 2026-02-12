<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed> test(\App\Models\DatabaseServer $server)
 *
 * @see \App\Services\DatabaseConnectionTester
 */
class DatabaseConnectionTester extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\DatabaseConnectionTester::class;
    }
}
