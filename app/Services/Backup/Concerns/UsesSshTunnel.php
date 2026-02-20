<?php

namespace App\Services\Backup\Concerns;

use App\Contracts\BackupLogger;
use App\Exceptions\SshTunnelException;
use App\Models\DatabaseServer;
use App\Services\SshTunnelService;

/**
 * Provides SSH tunnel management for backup and restore tasks.
 */
trait UsesSshTunnel
{
    /** @var array{host: string, port: int}|null */
    private ?array $tunnelEndpoint = null;

    abstract protected function getSshTunnelService(): SshTunnelService;

    /**
     * Establish SSH tunnel for the database server.
     *
     * @throws SshTunnelException
     */
    protected function establishSshTunnel(DatabaseServer $server, BackupLogger $logger): void
    {
        $sshConfig = $server->sshConfig;
        if ($sshConfig === null) {
            throw new SshTunnelException('SSH configuration not found for this server');
        }
        $this->tunnelEndpoint = $this->getSshTunnelService()->establish($server);

        $safeSshConfig = $sshConfig->getSafe();
        $logger->log('SSH tunnel established', 'success', [
            'local_port' => $this->tunnelEndpoint['port'],
            'ssh_host' => $safeSshConfig['host'] ?? null,
            'ssh_port' => $safeSshConfig['port'] ?? 22,
            'ssh_username' => $safeSshConfig['username'] ?? null,
        ]);
    }

    /**
     * Close SSH tunnel if active.
     */
    protected function closeSshTunnel(BackupLogger $logger): void
    {
        if ($this->getSshTunnelService()->isActive()) {
            $this->getSshTunnelService()->close();
            $logger->log('SSH tunnel closed');
        }
        $this->tunnelEndpoint = null;
    }

    /**
     * Get connection host, using tunnel endpoint if active.
     */
    protected function getConnectionHost(DatabaseServer $server): string
    {
        return $this->tunnelEndpoint['host'] ?? $server->host ?? '';
    }

    /**
     * Get connection port, using tunnel endpoint if active.
     */
    protected function getConnectionPort(DatabaseServer $server): int
    {
        return $this->tunnelEndpoint['port'] ?? $server->port;
    }
}
