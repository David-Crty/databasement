<?php

use App\Services\Adminer\AdminerSettings;

/**
 * Global adminer_object() function — must be in the root namespace
 * for Adminer to discover it via function_exists('adminer_object').
 *
 * Delegates to the AdminerSettings that AdminerService puts in $GLOBALS.
 */
function adminer_object()
{
    return new class($GLOBALS['_adminer_settings']) extends \Adminer\Adminer
    {
        public function __construct(private AdminerSettings $settings) {}

        /** @return array{string, string, string} */
        public function credentials(): array
        {
            return $this->settings->credentials() ?? parent::credentials();
        }

        public function login($login, $password)
        {
            return true;
        }

        public function verifyLoginToken(): bool
        {
            return $this->settings->verifyLoginToken();
        }

        public function headers()
        {
            header('X-Frame-Options: SAMEORIGIN');
        }

        /** @return array<string, string> */
        public function css()
        {
            return $this->settings->css();
        }
    };
}
