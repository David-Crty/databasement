<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum requests per minute allowed on the v1 API, counted per access
    | token, falling back to the authenticated user and then the client IP for
    | a request that carries neither. The default leaves room for busy
    | automation while still capping a runaway script. Set to 0 to disable
    | throttling, for example when a reverse proxy or gateway already enforces
    | its own limits.
    |
    | Agent daemon routes (/api/v1/agent/*) are deliberately excluded from
    | this limit — see routes/api.php.
    |
    | Only an explicit non-negative integer is honored. Anything else (unset,
    | blank, non-numeric, or negative) falls back to the default rather than
    | being coerced to 0, so a malformed value can never silently disable
    | throttling.
    |
    */

    'rate_limit' => (static function (): int {
        $default = 300;
        $raw = env('API_RATE_LIMIT');

        if (! is_string($raw) && ! is_int($raw)) {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return $value !== false && $value >= 0 ? $value : $default;
    })(),
];
