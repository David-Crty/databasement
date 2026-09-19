<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;
use App\Services\Backup\Databases\DatabaseProvider;

class MysqlConnectionRules extends ClientServerConnectionRules
{
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
     * Cached on the form, which drops the result as soon as a connection field
     * changes, so re-rendering the preview does not reconnect.
     */
    public function detectedServerVersion(Form $form): ?string
    {
        // Agent-backed servers are unreachable from here, and opening an SSH
        // tunnel costs tens of seconds, which no render may spend.
        if ($form->hasAgent() || $form->ssh_enabled) {
            return null;
        }

        if ($form->host === '' || $form->username === '') {
            return null;
        }

        if ($form->probedServerVersion === null) {
            $form->probedServerVersion = $this->probeServerVersion($form) ?? '';
        }

        return $form->probedServerVersion ?: null;
    }

    private function probeServerVersion(Form $form): ?string
    {
        try {
            return app(DatabaseProvider::class)
                ->serverVersionForServer($form->buildServerForTest(self::PROBE_TIMEOUT_SECONDS));
        } catch (\Throwable) {
            return null;
        }
    }
}
