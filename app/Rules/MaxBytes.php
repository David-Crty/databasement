<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bound a string by its byte length rather than its character count.
 *
 * Laravel's `max` rule measures characters (mb_strlen), which is the wrong
 * unit for limits the database enforces in bytes: PostgreSQL truncates any
 * identifier past NAMEDATALEN-1 (63 bytes), so a 63-character name written in
 * a multi-byte script passes `max:63` and is then silently cut short.
 */
readonly class MaxBytes implements ValidationRule
{
    public function __construct(private int $max) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (strlen($value) > $this->max) {
            $fail(__('The :attribute may not be greater than :max bytes.', ['max' => $this->max]));
        }
    }
}
