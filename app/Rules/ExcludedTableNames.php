<?php

namespace App\Rules;

use App\Models\DatabaseServer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Table names excluded from a dump are interpolated into `--ignore-table=` /
 * `--exclude-table=` flags. They are escaped before reaching the shell, but a
 * name carrying a dot, a space or a quote would still change which object the
 * dump tool matches, so restrict them to unquoted SQL identifier characters.
 *
 * Validates the raw textarea string the UI submits, comma- or newline-
 * separated. The REST API takes a JSON array instead and applies the same
 * limits through the constants below.
 */
readonly class ExcludedTableNames implements ValidationRule
{
    /** Unquoted MySQL/PostgreSQL identifier characters. */
    public const PATTERN = '/\A[A-Za-z0-9_$]+\z/';

    public const MAX_NAME_LENGTH = 64;

    public const MAX_NAMES = 200;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Anything but a non-empty string is already covered by the
        // accompanying nullable/string rules.
        if (! is_string($value) || $value === '') {
            return;
        }

        $names = DatabaseServer::parseExcludedTables($value);

        if (count($names) > self::MAX_NAMES) {
            $fail(__('The :attribute may not have more than :max table names.', ['max' => self::MAX_NAMES]));

            return;
        }

        foreach ($names as $name) {
            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $fail(__('The table name :name may not be greater than :max characters.', ['name' => $name, 'max' => self::MAX_NAME_LENGTH]));

                return;
            }

            if (preg_match(self::PATTERN, $name) !== 1) {
                $fail(__('The table name :name may only contain letters, numbers, underscores and dollar signs.', ['name' => $name]));

                return;
            }
        }
    }
}
