<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A database server host is interpolated into connection strings without
 * escaping: PDO DSNs delimited by `;` and `,`, and MongoDB URIs where `@`
 * separates credentials from the authority. A host carrying any of those
 * characters can redirect the connection elsewhere, so restrict it to what a
 * hostname, IPv4 address or IPv6 literal actually needs.
 */
readonly class SafeHost implements ValidationRule
{
    /**
     * Anchored with \A and \z rather than ^ and $, which would let a trailing
     * newline through. Shared with the connection-string builders that treat
     * this as their last line of defence.
     */
    public const PATTERN = '/\A[A-Za-z0-9._:\[\]-]+\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('The :attribute may only contain letters, numbers, dots, dashes, underscores, colons and square brackets.'));
        }
    }
}
