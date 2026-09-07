<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An SSH login name is handed to the ssh client, which rejects a leading dash
 * outright: it would otherwise be read as an option rather than a login name.
 * Reject it here as well, so the user gets a field-level error instead of an
 * opaque failure from the tunnel, along with anything ssh cannot carry.
 */
readonly class SafeUsername implements ValidationRule
{
    /**
     * Printable ASCII without spaces, not starting with a dash. Anchored with
     * \A and \z rather than ^ and $, which would let a trailing newline through.
     */
    public const PATTERN = '/\A[!-,.-~][!-~]*\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('The :attribute must not start with a dash and may only contain printable characters without spaces.'));
        }
    }
}
