<?php

namespace App\Enums\Concerns;

/**
 * The enum's cases as `x-select` / `x-choices` options, labelled by label().
 */
trait HasSelectOptions
{
    abstract public function label(): string;

    /**
     * @param  (\Closure(self): bool)|null  $filter  Keep only the cases it accepts
     * @return array<int, array{id: string, name: string}>
     */
    public static function toSelectOptions(?\Closure $filter = null): array
    {
        $cases = $filter === null ? self::cases() : array_filter(self::cases(), $filter);

        return array_values(array_map(
            fn (self $case): array => ['id' => $case->value, 'name' => $case->label()],
            $cases,
        ));
    }
}
