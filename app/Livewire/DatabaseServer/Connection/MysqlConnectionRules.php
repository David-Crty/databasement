<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Exceptions\Backup\EncryptionException;
use App\Livewire\DatabaseServer\Form;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;

class MysqlConnectionRules extends ClientServerConnectionRules
{
    /**
     * Connection timeout (seconds) for the version probe below. Shorter than
     * the form's other lookups because this one runs on render, so an
     * unreachable host must not hold up the form.
     */
    private const int PROBE_TIMEOUT_SECONDS = 2;

    public function extraConfig(Form $form): array
    {
        return $form->ssl_enabled ? ['ssl_enabled' => true] : [];
    }

    public function dumpPreviewConfig(Form $form): array
    {
        return [
            'ssl_enabled' => $form->ssl_enabled,
            'server_version' => $this->detectedServerVersion($form),
        ];
    }

    /**
     * Version of the server the connection fields point at, or null when there
     * is nothing to reach or it cannot be read. The dump preview reads it to
     * name the client the backup would really run.
     *
     * The result is cached on the form against the connection it came from, so
     * rendering the preview again does not reconnect.
     */
    public function detectedServerVersion(Form $form): ?string
    {
        if ($form->hasAgent() || $form->host === '' || $form->username === '') {
            return null;
        }

        try {
            $password = $form->password ?: $form->server?->getDecryptedPassword();
        } catch (EncryptionException) {
            return null;
        }

        // Keyed: this rides along in the component payload, and the plain hash
        // of a password is worth brute-forcing where an HMAC of one is not.
        $key = hash_hmac('sha256', implode('|', [
            $form->host,
            $form->port,
            $form->username,
            $password ?? '',
            (int) $form->ssl_enabled,
            (int) $form->ssh_enabled,
            $form->ssh_host,
            $form->ssh_port,
            $form->ssh_username,
        ]), (string) config('app.key'));

        if ($form->probedConnectionKey !== $key) {
            $form->probedConnectionKey = $key;
            $form->probedServerVersion = $this->probeServerVersion($form, $password ?? '') ?? '';
        }

        return $form->probedServerVersion ?: null;
    }

    private function probeServerVersion(Form $form, string $password): ?string
    {
        $extraConfig = $this->extraConfig($form);
        $extraConfig['connect_timeout'] = self::PROBE_TIMEOUT_SECONDS;

        $server = DatabaseServer::forConnectionTest([
            'host' => $form->host,
            'port' => $form->port,
            'database_type' => $form->database_type,
            'username' => $form->username,
            'password' => $password,
            'extra_config' => $extraConfig,
        ], $form->ssh_enabled ? $form->buildSshConfigForTest() : null);

        try {
            return app(DatabaseProvider::class)->serverVersionForServer($server);
        } catch (\Throwable) {
            return null;
        }
    }
}
