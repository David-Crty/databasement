<?php

namespace App\Rules;

use App\Enums\DatabaseType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the extra options appended to a dump command: the characters a
 * flag may contain, and the options it may not name.
 *
 * Some options name the file the dump client writes, so a dump carrying one
 * lands at a path of the caller's choosing rather than the destination
 * Databasement selected. Shell-quoting the tokens does not help: the option is
 * read by the dump client, not by the shell.
 *
 * Denied options are per engine because each client reads its own: `-r` is
 * `--result-file` to mariadb-dump but a repeat count to redis-cli, and `-f` is
 * `--file` to pg_dump but `--force` to mariadb-dump.
 *
 * `violation()` and `tokenize()` are shared with
 * {@see \App\Services\Backup\DTO\DatabaseOperationResult::escapeFlags()}, which
 * treats them as its last line of defence for configurations stored before a
 * flag was denied.
 */
readonly class SafeDumpFlags implements ValidationRule
{
    public const string PATTERN = '/\A[a-zA-Z0-9\s\-\_\=\.\/\,\:\*\?\%\+\@]+\z/';

    /**
     * Options that let the caller choose a path the dump client writes, or one
     * it loads further options or code from. Short forms are listed alongside
     * the long ones they abbreviate. Every spelling here was checked against
     * the client shipped in the runtime image.
     *
     * @var array<string, list<string>>
     */
    private const DENIED = [
        // mariadb-dump
        DatabaseType::MYSQL->value => [
            '--result-file', '-r',   // the dump itself
            '--tab', '-T',           // one .sql file per table, into a directory
            '--dir', '-D',           // directory-format backup (MariaDB 11+)
            '--log-error',           // warnings and errors
            '--defaults-file', '--defaults-extra-file', // options, --result-file among them
            '--plugin-dir',          // client-side plugins, loaded as code
        ],
        // pg_dump
        DatabaseType::POSTGRESQL->value => [
            '--file', '-f',          // output file or directory
        ],
        // mongodump
        DatabaseType::MONGODB->value => [
            '--out', '-o',           // output directory
            '--archive',             // archive path; the dump sets its own
            '--config',              // options, read from a YAML file
        ],
        // redis-cli
        DatabaseType::REDIS->value => [
            '--rdb', '--functions-rdb', // the dump itself; the dump sets its own
            '--eval',                // runs a local Lua file on the server
            '--pipe', '-x', '-X',    // feeds stdin to the server
        ],
        // sqlpackage
        DatabaseType::MSSQL->value => [
            '/targetfile', '/tf',    // the dump itself; the dump sets its own
            '/diagnosticsfile', '/df', // diagnostic log
            '/profile', '/pr',       // publish profile, TargetFile among its settings
        ],
    ];

    public function __construct(private ?DatabaseType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('The :attribute contains characters that are not allowed in a dump flag.'));

            return;
        }

        $violation = self::violation($value, $this->type);

        if ($violation !== null) {
            $fail(__('The :attribute may not contain :option, which redirects where the dump is written.', [
                'option' => $violation,
            ]));
        }
    }

    /**
     * The first option in $flags denied for $type, or null when there is none.
     *
     * Both `--result-file=path` and `--result-file path` reduce to the same
     * name here, since the name is always its own whitespace-delimited token.
     *
     * Long names are compared case-insensitively, which only ever refuses more
     * than the client would accept. Short ones are compared as written, because
     * their case is what distinguishes them: to mariadb-dump `-R` is
     * `--routines`, `-d` is `--no-data` and `-t` is `--no-create-info`, none of
     * which have anything to do with `-r`, `-D` or `-T`.
     */
    public static function violation(?string $flags, ?DatabaseType $type): ?string
    {
        $denied = self::DENIED[$type?->value] ?? [];

        if ($denied === []) {
            return null;
        }

        foreach (self::tokenize($flags) as $token) {
            if (array_intersect(self::optionNames($token, $type), $denied) !== []) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The option names a token carries.
     *
     * sqlpackage spells its options `/Name:Value`, case-insensitively, so the
     * name ends at the first colon. Everywhere else a long name ends at the
     * first `=`.
     *
     * A short token is a cluster rather than a single option: pg_dump and
     * mariadb-dump both read `-vf/tmp/x` as `-v` followed by `-f` carrying
     * `/tmp/x`, so every character in the cluster counts as an option. Telling
     * an option apart from the value attached to an earlier one would take each
     * client's full option table, so a value whose text happens to contain a
     * denied letter is refused as well.
     *
     * The MySQL clients read `_` and `-` as the same character in a long name,
     * so `--result_file` reaches the same code as `--result-file` (the dump
     * command relies on this itself, passing `--skip_ssl`). No other client
     * here does: pg_dump, mongodump and redis-cli all refuse the underscored
     * spelling outright.
     *
     * @return list<string>
     */
    private static function optionNames(string $token, ?DatabaseType $type): array
    {
        if ($type === DatabaseType::MSSQL) {
            return [strtolower(strstr($token, ':', true) ?: $token)];
        }

        if (str_starts_with($token, '--')) {
            $name = strtolower(strstr($token, '=', true) ?: $token);

            return [$type === DatabaseType::MYSQL ? str_replace('_', '-', $name) : $name];
        }

        if (! str_starts_with($token, '-')) {
            return [$token];
        }

        return array_map(
            static fn (string $character): string => '-'.$character,
            str_split(substr($token, 1)),
        );
    }

    /**
     * @return list<string>
     */
    public static function tokenize(?string $flags): array
    {
        if ($flags === null || trim($flags) === '') {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/', trim($flags), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens;
    }
}
