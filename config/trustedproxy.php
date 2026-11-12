<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Read by Laravel's TrustProxies middleware on every request. Keeping the
    | TRUSTED_PROXIES lookup in a config file (rather than in bootstrap/app.php)
    | means the value from .env still applies after `php artisan optimize`.
    |
    | Use '*' to trust all proxies, or specify IPs/CIDR. An empty value trusts
    | no proxy at all.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', TRUSTED_PROXIES_DEFAULT) ?: null,

];
