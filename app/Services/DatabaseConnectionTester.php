<?php

namespace App\Services;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseFactory;
use App\Services\Backup\Filesystems\SftpFilesystem;

class DatabaseConnectionTester
{
    public function __construct(
        private readonly DatabaseFactory $databaseFactory,
        private readonly SshTunnelService $sshTunnelService,
        private readonly SftpFilesystem $sftpFilesystem,
    ) {}

    /**
     * Test a database connection, establishing an SSH tunnel first if configured.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function test(DatabaseServer $server): array
    {
        if ($server->requiresSftpTransfer()) {
            return $this->testSftp($server);
        }

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
     * Test remote SQLite connection via SFTP: verify file exists on remote server.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private function testSftp(DatabaseServer $server): array
    {
        $sshConfig = $server->sshConfig;
        if ($sshConfig === null) {
            return ['success' => false, 'message' => 'SSH configuration not found for this server.', 'details' => []];
        }

        $remotePath = $server->sqlite_path ?? '';
        if (empty($remotePath)) {
            return ['success' => false, 'message' => 'Database file path is required.', 'details' => []];
        }

        try {
            $filesystem = $this->sftpFilesystem->getFromSshConfig($sshConfig);

            if (! $filesystem->fileExists($remotePath)) {
                return ['success' => false, 'message' => 'Remote file does not exist: '.$remotePath, 'details' => []];
            }

            $fileSize = $filesystem->fileSize($remotePath);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'sftp' => true,
                    'ssh_host' => $sshConfig->host,
                    'output' => json_encode([
                        'dbms' => 'SQLite (remote)',
                        'file_size' => $fileSize,
                        'path' => $remotePath,
                        'access' => 'SFTP via '.$sshConfig->getDisplayName(),
                    ], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'SFTP connection failed: '.$e->getMessage(), 'details' => []];
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
