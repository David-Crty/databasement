<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Database names are handed to dump and restore clients on their command
 * line, where a leading dash would be read as an option.
 */
readonly class SafeDatabaseName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_starts_with($value, '-')) {
            $fail(__('The database name must not start with a dash.'));
        }
    }
}
