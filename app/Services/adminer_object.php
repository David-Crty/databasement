<?php

/**
 * Global adminer_object() function — must be in the root namespace
 * for Adminer to discover it via function_exists('adminer_object').
 *
 * Reads credentials and CSS path from $GLOBALS, set by AdminerService.
 */
function adminer_object()
{
    $credentials = $GLOBALS['_adminer_credentials'] ?? null;
    $cssPath = $GLOBALS['_adminer_css_path'] ?? '/css/adminer.css';

    return new class($credentials, $cssPath) extends \Adminer\Adminer
    {
        /** @param array<string, string>|null $creds */
        public function __construct(
            private ?array $creds,
            private string $cssPath,
        ) {}

        /** @return array{string, string, string} */
        public function credentials(): array
        {
            if ($this->creds) {
                return [$this->creds['server'], $this->creds['username'], $this->creds['password']];
            }

            return parent::credentials();
        }

        public function login($login, $password)
        {
            return true;
        }

        public function headers()
        {
            header('X-Frame-Options: SAMEORIGIN');
        }

        /** @return array{string} */
        public function css()
        {
            return [$this->cssPath];
        }
    };
}
