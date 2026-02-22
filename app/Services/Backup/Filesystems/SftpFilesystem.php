<?php

namespace App\Services\Backup\Filesystems;

use App\Models\DatabaseServerSshConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

class SftpFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'sftp';
    }

    /**
     * @param  array{host: string, username: string, password?: string|null, private_key?: string|null, key_passphrase?: string|null, port?: int, root?: string, timeout?: int}  $config
     */
    public function get(array $config): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: $config['host'],
            username: $config['username'],
            password: $config['password'] ?? null,
            privateKey: $config['private_key'] ?? null,
            passphrase: $config['key_passphrase'] ?? null,
            port: (int) ($config['port'] ?? 22),
            timeout: (int) ($config['timeout'] ?? 10),
        );

        $root = $config['root'] ?? '/';

        $adapter = new SftpAdapter($provider, $root);

        return new Filesystem($adapter);
    }

    /**
     * Build a Flysystem Filesystem from a DatabaseServerSshConfig model.
     * Convenience method to avoid duplicating SSH-to-SFTP config mapping.
     */
    public function getFromSshConfig(DatabaseServerSshConfig $sshConfig, string $root = '/'): Filesystem
    {
        return $this->getFromDecryptedConfig($sshConfig->getDecrypted(), $root);
    }

    /**
     * Build a Flysystem Filesystem from a decrypted SSH config array.
     *
     * @param  array<string, mixed>  $decryptedConfig  Decrypted SSH config (host, port, username, password, private_key, key_passphrase)
     */
    public function getFromDecryptedConfig(array $decryptedConfig, string $root = '/'): Filesystem
    {
        return $this->get([
            'host' => $decryptedConfig['host'],
            'port' => $decryptedConfig['port'],
            'username' => $decryptedConfig['username'],
            'password' => $decryptedConfig['password'] ?? null,
            'private_key' => $decryptedConfig['private_key'] ?? null,
            'key_passphrase' => $decryptedConfig['key_passphrase'] ?? null,
            'root' => $root,
        ]);
    }
}
