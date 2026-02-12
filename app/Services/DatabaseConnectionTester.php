<?php

namespace App\Services;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseFactory;

class DatabaseConnectionTester
{
    public function __construct(
        private readonly DatabaseFactory $databaseFactory,
        private readonly SshTunnelService $sshTunnelService,
    ) {}

    /**
     * Test a database connection, establishing an SSH tunnel first if configured.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function test(DatabaseServer $server): array
    {
        if ($server->requiresSshTunnel()) {
            $sshResult = $this->testSsh($server);
            if (! $sshResult['success']) {
                return $sshResult;
            }

            /** @var array{success: true, host: string, port: int, message: string, details: array<string, mixed>} $sshResult */
            $server->host = $sshResult['host'];
            $server->port = $sshResult['port'];
        }

        try {
            $result = $this->testDatabase($server);

            if ($result['success'] && $server->requiresSshTunnel()) {
                $result['details']['ssh_tunnel'] = true;
                $result['details']['ssh_host'] = $server->sshConfig->host;
            }

            return $result;
        } finally {
            $this->sshTunnelService->close();
        }
    }

    /**
     * Validate and establish an SSH tunnel for the database test.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>, host?: string, port?: int}
     */
    private function testSsh(DatabaseServer $server): array
    {
        $sshResult = $this->sshTunnelService->testConnection($server->sshConfig);
        if (! $sshResult['success']) {
            return ['success' => false, 'message' => 'SSH connection failed: '.$sshResult['message'], 'details' => []];
        }

        try {
            $tunnelEndpoint = $this->sshTunnelService->establish($server);

            return [
                'success' => true,
                'message' => 'SSH tunnel established',
                'details' => [],
                'host' => $tunnelEndpoint['host'],
                'port' => $tunnelEndpoint['port'],
            ];
        } catch (\Throwable $e) {
            $this->sshTunnelService->close();

            return ['success' => false, 'message' => 'Failed to establish SSH tunnel: '.$e->getMessage(), 'details' => []];
        }
    }

    /**
     * Test the database connection using the appropriate database handler.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private function testDatabase(DatabaseServer $server): array
    {
        $databaseName = $server->database_type === DatabaseType::POSTGRESQL ? 'postgres' : '';
        $database = $this->databaseFactory->makeForServer($server, $databaseName, $server->host, $server->port);

        return $database->testConnection();
    }
}
