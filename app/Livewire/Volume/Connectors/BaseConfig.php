<?php

namespace App\Livewire\Volume\Connectors;

abstract class BaseConfig
{
    /**
     * @return array<string, mixed>
     */
    abstract public static function defaultConfig(): array;

    /**
     * @return array<string, mixed>
     */
    abstract public static function rules(string $prefix): array;
}
