<?php

namespace App\Services;

use App\Exceptions\SshTunnelException;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use Symfony\Component\Process\Process;

class SshTunnelService
{
    private const CONNECTION_TIMEOUT_SECONDS = 30;

    private const WAIT_INTERVAL_MS = 100;

    /** Derived from CONNECTION_TIMEOUT_SECONDS * 1000 / WAIT_INTERVAL_MS */
    private const MAX_WAIT_ATTEMPTS = 300;

    private ?Process $tunnelProcess = null;

    private ?int $localPort = null;

    private ?string $keyFile = null;

    private ?string $askPassScript = null;

    /**
     * Establish an SSH tunnel for the given database server.
     *
     * @return array{host: string, port: int} The local endpoint to connect to
     *
     * @throws SshTunnelException
     */
    public function establish(DatabaseServer $server): array
    {
        if (! $server->requiresSshTunnel()) {
            throw new SshTunnelException('SSH tunnel is not configured for this server');
        }

        $sshConfig = $server->sshConfig;
        if ($sshConfig === null) {
            throw new SshTunnelException('SSH configuration not found for this server');
        }

        $decrypted = $sshConfig->getDecrypted();

        $this->localPort = $this->allocateLocalPort();
        $command = $this->buildSshCommand(
            sshHost: $decrypted['host'],
            sshPort: (int) ($decrypted['port'] ?? 22),
            sshUsername: $decrypted['username'],
            authType: $decrypted['auth_type'] ?? 'password',
            password: $decrypted['password'] ?? null,
            privateKey: $decrypted['private_key'] ?? null,
            keyPassphrase: $decrypted['key_passphrase'] ?? null,
            remoteHost: $server->host,
            remotePort: $server->port,
            localPort: $this->localPort
        );

        $this->tunnelProcess = $this->createTunnelProcess($command);
        $this->tunnelProcess->setTimeout(null);
        $this->tunnelProcess->start();

        // Wait for tunnel to be established
        if (! $this->waitForTunnel()) {
            $errorOutput = $this->tunnelProcess->getErrorOutput();
            $this->close();
            throw new SshTunnelException(
                'Failed to establish SSH tunnel: '.$this->sanitizeError($errorOutput),
                sshErrorOutput: $errorOutput
            );
        }

        return [
            'host' => '127.0.0.1',
            'port' => $this->localPort,
        ];
    }

    /**
     * Close the SSH tunnel and clean up resources.
     */
    public function close(): void
    {
        if ($this->tunnelProcess !== null && $this->tunnelProcess->isRunning()) {
            $this->tunnelProcess->stop(3);
        }

        $this->tunnelProcess = null;
        $this->localPort = null;
        $this->cleanupTempFiles();
    }

    /**
     * Test an SSH connection without establishing a tunnel.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public static function testConnection(DatabaseServerSshConfig $sshConfig): array
    {
        $instance = new self;

        try {
            $decrypted = $sshConfig->getDecrypted();
            $command = $instance->buildTestCommand($decrypted);

            $process = Process::fromShellCommandLine($command);
            $process->setTimeout(self::CONNECTION_TIMEOUT_SECONDS);

            $startTime = microtime(true);
            $process->run();
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if (! $process->isSuccessful()) {
                $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput());

                return [
                    'success' => false,
                    'message' => $instance->sanitizeError($errorOutput) ?: 'SSH connection failed',
                    'details' => [],
                ];
            }

            return [
                'success' => true,
                'message' => 'SSH connection successful',
                'details' => [
                    'ping_ms' => $durationMs,
                ],
            ];
        } finally {
            $instance->cleanupTempFiles();
        }
    }

    /**
     * Check if the tunnel is currently active.
     */
    public function isActive(): bool
    {
        return $this->tunnelProcess !== null && $this->tunnelProcess->isRunning();
    }

    /**
     * Get the local port being used for the tunnel.
     */
    public function getLocalPort(): ?int
    {
        return $this->localPort;
    }

    /**
     * Allocate an available local port by binding to port 0.
     *
     * @throws SshTunnelException
     */
    protected function allocateLocalPort(): int
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new SshTunnelException('Failed to create socket for port allocation');
        }

        if (! @socket_bind($socket, '127.0.0.1', 0)) {
            socket_close($socket);
            throw new SshTunnelException('Failed to bind socket for port allocation');
        }

        if (! @socket_getsockname($socket, $addr, $port)) {
            socket_close($socket);
            throw new SshTunnelException('Failed to get allocated port');
        }

        socket_close($socket);

        return $port;
    }

    /**
     * Create a Process instance for the tunnel command.
     * Protected to allow mocking in tests.
     */
    protected function createTunnelProcess(string $command): Process
    {
        return Process::fromShellCommandLine($command);
    }

    /**
     * Build the SSH tunnel command.
     */
    private function buildSshCommand(
        string $sshHost,
        int $sshPort,
        string $sshUsername,
        string $authType,
        ?string $password,
        ?string $privateKey,
        ?string $keyPassphrase,
        string $remoteHost,
        int $remotePort,
        int $localPort
    ): string {
        // BatchMode=yes only for passphrase-less key auth; password and key+passphrase need interactive mode
        $batchMode = $authType === 'key' && empty($keyPassphrase);

        $sshOptions = $this->buildBaseOptions($sshPort, $batchMode, [
            '-N', // Don't execute remote command
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'ExitOnForwardFailure=yes',
            '-L', sprintf('%d:%s:%d', $localPort, $remoteHost, $remotePort),
        ]);

        return $this->buildAuthenticatedCommand(
            $sshOptions,
            $sshHost,
            $sshUsername,
            $authType,
            $password,
            $privateKey,
            $keyPassphrase
        );
    }

    /**
     * Build command to test SSH connection.
     *
     * @param  array<string, mixed>  $sshConfig
     */
    private function buildTestCommand(array $sshConfig): string
    {
        $sshHost = $sshConfig['host'] ?? '';
        $sshPort = (int) ($sshConfig['port'] ?? 22);
        $sshUsername = $sshConfig['username'] ?? '';
        $authType = $sshConfig['auth_type'] ?? 'password';
        $password = $sshConfig['password'] ?? null;
        $privateKey = $sshConfig['private_key'] ?? null;
        $keyPassphrase = $sshConfig['key_passphrase'] ?? null;

        // BatchMode=yes only for passphrase-less key auth; password and key+passphrase need interactive mode
        $batchMode = $authType === 'key' && empty($keyPassphrase);

        $sshOptions = $this->buildBaseOptions($sshPort, $batchMode);

        return $this->buildAuthenticatedCommand(
            $sshOptions,
            $sshHost,
            $sshUsername,
            $authType,
            $password,
            $privateKey,
            $keyPassphrase,
            'exit 0'
        );
    }

    /**
     * Build base SSH options common to all commands.
     *
     * @param  bool  $batchMode  Use BatchMode=yes for non-interactive auth (passphrase-less keys), BatchMode=no for password/passphrase auth
     * @param  array<string>  $additionalOptions
     * @return array<string>
     */
    private function buildBaseOptions(int $sshPort, bool $batchMode = true, array $additionalOptions = []): array
    {
        return array_merge([
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', $batchMode ? 'BatchMode=yes' : 'BatchMode=no',
            '-o', sprintf('ConnectTimeout=%d', self::CONNECTION_TIMEOUT_SECONDS),
            '-p', (string) $sshPort,
        ], $additionalOptions);
    }

    /**
     * Build the final SSH command with authentication.
     *
     * @param  array<string>  $sshOptions
     */
    private function buildAuthenticatedCommand(
        array $sshOptions,
        string $sshHost,
        string $sshUsername,
        string $authType,
        ?string $password,
        ?string $privateKey,
        ?string $keyPassphrase,
        string $remoteCommand = ''
    ): string {
        $optionsString = implode(' ', array_map('escapeshellarg', $sshOptions));
        $userHost = escapeshellarg($sshUsername).'@'.escapeshellarg($sshHost);
        $suffix = $remoteCommand !== '' ? ' '.$remoteCommand : '';

        if ($authType === 'key' && ! empty($privateKey)) {
            $this->keyFile = $this->writeKeyToTempFile($privateKey);
            $optionsString .= ' -i '.escapeshellarg($this->keyFile);

            if (! empty($keyPassphrase)) {
                try {
                    $this->askPassScript = $this->createAskPassScript($keyPassphrase);
                } catch (SshTunnelException $e) {
                    // Clean up the key file if askpass creation fails
                    @unlink($this->keyFile);
                    $this->keyFile = null;
                    throw $e;
                }

                return sprintf(
                    'SSH_ASKPASS=%s SSH_ASKPASS_REQUIRE=force setsid ssh %s %s%s',
                    escapeshellarg($this->askPassScript),
                    $optionsString,
                    $userHost,
                    $suffix
                );
            }
        } elseif ($authType === 'password' && ! empty($password)) {
            // Use SSHPASS env var with -e flag to avoid exposing password in process args
            return sprintf(
                'SSHPASS=%s sshpass -e ssh %s %s%s',
                escapeshellarg($password),
                $optionsString,
                $userHost,
                $suffix
            );
        }

        return sprintf('ssh %s %s%s', $optionsString, $userHost, $suffix);
    }

    /**
     * Write private key to a temporary file with proper permissions.
     *
     * @throws SshTunnelException
     */
    private function writeKeyToTempFile(string $key): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ssh_key_');
        if ($tempFile === false) {
            throw new SshTunnelException('Failed to create temporary file for SSH key');
        }

        if (file_put_contents($tempFile, $key) === false) {
            @unlink($tempFile);
            throw new SshTunnelException('Failed to write SSH key to temporary file');
        }

        if (chmod($tempFile, 0600) === false) {
            @unlink($tempFile);
            throw new SshTunnelException('Failed to set permissions on SSH key temporary file');
        }

        return $tempFile;
    }

    /**
     * Create a temporary script for SSH_ASKPASS.
     *
     * @throws SshTunnelException
     */
    private function createAskPassScript(string $passphrase): string
    {
        $script = tempnam(sys_get_temp_dir(), 'askpass_');
        if ($script === false) {
            throw new SshTunnelException('Failed to create askpass script');
        }

        $content = "#!/bin/bash\necho ".escapeshellarg($passphrase);
        if (file_put_contents($script, $content) === false) {
            @unlink($script);
            throw new SshTunnelException('Failed to write askpass script');
        }

        if (chmod($script, 0700) === false) {
            @unlink($script);
            throw new SshTunnelException('Failed to set permissions on askpass script');
        }

        return $script;
    }

    /**
     * Wait for the tunnel to be established by checking if the local port is accepting connections.
     * Protected to allow mocking in tests.
     */
    protected function waitForTunnel(): bool
    {
        for ($i = 0; $i < self::MAX_WAIT_ATTEMPTS; $i++) {
            // Check if the process has terminated with an error
            if (! $this->tunnelProcess->isRunning()) {
                return false;
            }

            // Try to connect to the local port
            $socket = @fsockopen('127.0.0.1', $this->localPort, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(self::WAIT_INTERVAL_MS * 1000);
        }

        return false;
    }

    /**
     * Sanitize SSH error output for display.
     */
    private function sanitizeError(string $error): string
    {
        $lines = explode("\n", trim($error));
        $filtered = array_filter($lines, fn (string $line) => $line !== '' && ! str_starts_with($line, 'Warning:'));

        return implode(' ', array_map('trim', $filtered));
    }

    /**
     * Clean up temporary files (key file and askpass script).
     */
    private function cleanupTempFiles(): void
    {
        foreach ([$this->keyFile, $this->askPassScript] as $file) {
            if ($file !== null && file_exists($file)) {
                unlink($file);
            }
        }
        $this->keyFile = null;
        $this->askPassScript = null;
    }
}
