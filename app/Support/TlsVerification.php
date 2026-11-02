<?php

namespace App\Support;

/**
 * Guzzle `verify` option for connections to endpoints an operator hosts
 * themselves: OAuth providers, and the server an agent reports to. These often
 * sit behind a private CA or a self-signed certificate.
 *
 * A CA bundle replaces the system trust store for these connections rather than
 * adding to it, so it must hold every CA they need.
 */
final class TlsVerification
{
    public static function option(): bool|string
    {
        if (! config('app.tls_verify')) {
            return false;
        }

        return config('app.tls_ca_bundle') ?: true;
    }
}
