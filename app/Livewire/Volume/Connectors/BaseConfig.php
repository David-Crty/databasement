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

    /**
     * The same rules for a request that already knows the volume type, where
     * a field required for this type is simply required.
     *
     * @return array<string, mixed>
     */
    public static function requestRules(string $prefix): array
    {
        return array_map(
            fn (array $fieldRules): array => array_map(
                fn (mixed $rule): mixed => is_string($rule) && str_starts_with($rule, 'required_if:type,') ? 'required' : $rule,
                $fieldRules,
            ),
            static::rules($prefix),
        );
    }
}
