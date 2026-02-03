<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed> test(array<string, mixed> $config, \App\Models\DatabaseServerSshConfig|null $sshConfig = null)
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
