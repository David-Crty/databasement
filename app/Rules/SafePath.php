<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

readonly class SafePath implements ValidationRule
{
    public function __construct(
        private bool $allowAbsolute = false
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // Reject path traversal sequences
        if (str_contains($value, '..')) {
            $fail('The :attribute must not contain path traversal sequences (..).');

            return;
        }

        // Reject backslashes (Windows-style paths)
        if (str_contains($value, '\\')) {
            $fail('The :attribute must not contain backslashes.');

            return;
        }

        // Reject absolute paths if not allowed
        if (! $this->allowAbsolute && $this->isAbsolutePath($value)) {
            $fail('The :attribute must be a relative path.');

            return;
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        // Unix absolute path
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path (e.g., C:\, D:/)
        if (preg_match('/^[a-zA-Z]:\//', $path)) {
            return true;
        }

        return false;
    }
}
