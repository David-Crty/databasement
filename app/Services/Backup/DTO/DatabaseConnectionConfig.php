<?php

namespace App\Services\Backup\DTO;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;

readonly class DatabaseConnectionConfig
{
    /**
     * @param  array<string, mixed>|null  $extraConfig
     * @param  array<string, mixed>|null  $sshConfig  Decrypted SSH config array (host, port, username, auth_type, password, private_key, key_passphrase)
     */
    public function __construct(
        public DatabaseType $databaseType,
        public string $serverName,
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public ?array $extraConfig = null,
        public ?array $sshConfig = null,
    ) {}

    public function requiresSshTunnel(): bool
    {
        return $this->databaseType !== DatabaseType::SQLITE
            && $this->sshConfig !== null;
    }

    /**
     * Get SSH config with sensitive fields removed (for logging).
     *
     * @return array<string, mixed>|null
     */
    public function getSafeSshConfig(): ?array
    {
        if ($this->sshConfig === null) {
            return null;
        }

        return [
            'host' => $this->sshConfig['host'] ?? null,
            'port' => $this->sshConfig['port'] ?? 22,
            'username' => $this->sshConfig['username'] ?? null,
            'auth_type' => $this->sshConfig['auth_type'] ?? null,
        ];
    }

    public static function fromServer(DatabaseServer $server): self
    {
        $sshConfig = null;
        if ($server->sshConfig !== null) {
            $sshConfig = $server->sshConfig->getDecrypted();
        }

        return new self(
            databaseType: $server->database_type,
            serverName: $server->name,
            host: $server->host ?? '',
            port: $server->port,
            username: $server->username ?? '',
            password: $server->getDecryptedPassword(),
            extraConfig: $server->extra_config,
            sshConfig: $sshConfig,
        );
    }
}
