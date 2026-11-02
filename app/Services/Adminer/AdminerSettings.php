<?php

namespace App\Services\Adminer;

/**
 * The decisions behind the adminer_object() customization, kept out of the
 * anonymous class because that one can only exist once Adminer is loaded.
 */
readonly class AdminerSettings
{
    /**
     * @param  array<string, string>|null  $credentials  null on requests after the initial auto-login
     */
    public function __construct(
        private ?array $credentials,
        private string $cssPath,
    ) {}

    /**
     * @return array{string, string, string}|null server, username and password, or null to let Adminer use its session
     */
    public function credentials(): ?array
    {
        if ($this->credentials === null) {
            return null;
        }

        return [$this->credentials['server'], $this->credentials['username'], $this->credentials['password']];
    }

    /** The injected credentials come from the server, not from a form that could carry the token. */
    public function verifyLoginToken(): bool
    {
        return $this->credentials === null;
    }

    /**
     * '' declares support for both colour schemes, so Adminer adds its dark.css and color-scheme meta.
     *
     * @return array<string, string>
     */
    public function css(): array
    {
        return [$this->cssPath => ''];
    }
}
