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

        /** The injected credentials come from the server, not from a form that could carry the token. */
        public function verifyLoginToken(): bool
        {
            return $this->creds === null;
        }

        public function headers()
        {
            header('X-Frame-Options: SAMEORIGIN');
        }

        /**
         * '' declares support for both colour schemes, so Adminer adds its dark.css and color-scheme meta.
         *
         * @return array<string, string>
         */
        public function css()
        {
            return [$this->cssPath => ''];
        }
    };
}
