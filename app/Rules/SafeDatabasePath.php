<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Path-shaped database names (SQLite files, Firebird aliases) are written to
 * directly at restore time, so they must not be able to escape their directory.
 *
 * Distinct from {@see SafePath}, which guards paths relative to a storage root:
 * these are absolute on the database host, and Firebird needs Windows-style
 * backslashes. Traversal is matched per segment, so `app..sqlite` stays valid.
 */
readonly class SafeDatabasePath implements ValidationRule
{
    public function __construct(
        private bool $allowBackslashes = false
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (str_contains($value, "\0")) {
            $fail(__('The database path must not contain null bytes.'));

            return;
        }

        if (! $this->allowBackslashes && str_contains($value, '\\')) {
            $fail(__('The database path must not contain backslashes.'));

            return;
        }

        $segments = explode('/', str_replace('\\', '/', $value));

        if (in_array('..', $segments, true)) {
            $fail(__('The database path must not contain path traversal sequences (..).'));
        }
    }
}
