<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static void flush()
 *
 * @see \App\Services\AppConfigService
 */
class AppConfig extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\AppConfigService::class;
    }
}
