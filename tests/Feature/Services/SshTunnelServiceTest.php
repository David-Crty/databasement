<?php

use App\Exceptions\SshTunnelException;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\SshTunnelService;

test('throws exception when SSH is not configured', function () {
    $server = DatabaseServer::factory()->make(); // No SSH config

    $service = new SshTunnelService;

    expect(fn () => $service->establish($server))
        ->toThrow(SshTunnelException::class, 'SSH tunnel is not configured for this server');
});

test('service has safe defaults when no tunnel is established', function () {
    $service = new SshTunnelService;

    expect($service->isActive())->toBeFalse()
        ->and($service->getLocalPort())->toBeNull();

    // close should not throw
    $service->close();
    expect($service->isActive())->toBeFalse();
});

test('testConnection returns error for invalid config', function (array $config) {
    $sshConfig = new DatabaseServerSshConfig;
    foreach ($config as $key => $value) {
        $sshConfig->$key = $value;
    }

    $result = app(SshTunnelService::class)->testConnection($sshConfig);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toBeEmpty();
})->with([
    'invalid host' => [[
        'host' => 'nonexistent.invalid.host.example',
        'port' => 22,
        'username' => 'test',
        'auth_type' => 'password',
        'password' => 'test',
    ]],
    'empty credentials' => [[
        'host' => 'bastion.example.com',
        'port' => 22,
        'username' => '',
        'auth_type' => 'password',
        'password' => '',
    ]],
]);

test('testConnection cleans up key file after test', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'nonexistent.invalid.host.example';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'key';
    $sshConfig->private_key = "-----BEGIN OPENSSH PRIVATE KEY-----\ntest_key\n-----END OPENSSH PRIVATE KEY-----";
    $sshConfig->key_passphrase = null;

    $result = app(SshTunnelService::class)->testConnection($sshConfig);

    // Test should fail but that's expected - we're testing cleanup
    expect($result['success'])->toBeFalse();

    // Key file should have been cleaned up (no lingering temp files)
    $tempFiles = glob(sys_get_temp_dir().'/ssh_key_*');
    expect($tempFiles)->toBeEmpty();
});

test('testConnection handles key with passphrase', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'nonexistent.invalid.host.example';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'key';
    $sshConfig->private_key = "-----BEGIN OPENSSH PRIVATE KEY-----\ntest_key\n-----END OPENSSH PRIVATE KEY-----";
    $sshConfig->key_passphrase = 'secret_passphrase';

    $result = app(SshTunnelService::class)->testConnection($sshConfig);

    // Test should fail but that's expected - we're verifying the code path doesn't throw
    expect($result['success'])->toBeFalse();
    expect($result['message'])->not->toBeEmpty();
});

test('establish returns local endpoint on successful tunnel', function () {
    // Create SSH config without persisting to database
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 22;
    $sshConfig->username = 'tunnel_user';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'ssh_password';

    // Create server with SSH config relationship set
    $server = new DatabaseServer;
    $server->host = 'database.internal';
    $server->port = 3306;
    $server->database_type = 'mysql';
    $server->ssh_config_id = 'temp';
    $server->setRelation('sshConfig', $sshConfig);

    // Mock the Process
    $mockProcess = Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(null);
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('stop')->andReturn(0);

    // Create partial mock of SshTunnelService
    $service = Mockery::mock(SshTunnelService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('allocateLocalPort')->once()->andReturn(54321);
    $service->shouldReceive('createTunnelProcess')->once()->andReturn($mockProcess);
    $service->shouldReceive('waitForTunnel')->once()->andReturn(true);

    // Act
    $result = $service->establish($server);

    // Assert
    expect($result)->toBe(['host' => '127.0.0.1', 'port' => 54321]);
    expect($service->getLocalPort())->toBe(54321);
    expect($service->isActive())->toBeTrue();

    // Cleanup
    $service->close();
});

test('establish throws exception when tunnel fails to connect', function () {
    // Create SSH config without persisting to database
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 22;
    $sshConfig->username = 'tunnel_user';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'ssh_password';

    // Create server with SSH config relationship set
    $server = new DatabaseServer;
    $server->host = 'database.internal';
    $server->port = 3306;
    $server->database_type = 'mysql';
    $server->ssh_config_id = 'temp';
    $server->setRelation('sshConfig', $sshConfig);

    // Mock the Process to simulate failure
    $mockProcess = Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(null);
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(false);
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('Connection refused');
    $mockProcess->shouldReceive('stop')->andReturn(0);

    // Create partial mock
    $service = Mockery::mock(SshTunnelService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('allocateLocalPort')->once()->andReturn(54321);
    $service->shouldReceive('createTunnelProcess')->once()->andReturn($mockProcess);
    $service->shouldReceive('waitForTunnel')->once()->andReturn(false);

    // Act & Assert
    expect(fn () => $service->establish($server))
        ->toThrow(SshTunnelException::class, 'Failed to establish SSH tunnel');

    // Service should have cleaned up
    expect($service->isActive())->toBeFalse();
    expect($service->getLocalPort())->toBeNull();
});

test('establish with key auth creates temp key file', function () {
    // Create SSH config without persisting to database
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 2222;
    $sshConfig->username = 'key_user';
    $sshConfig->auth_type = 'key';
    $sshConfig->private_key = 'FAKE_SSH_PRIVATE_KEY_FOR_TESTS_ONLY';
    $sshConfig->key_passphrase = null;

    // Create server with SSH config relationship set
    $server = new DatabaseServer;
    $server->host = 'postgres.internal';
    $server->port = 5432;
    $server->database_type = 'postgres';
    $server->ssh_config_id = 'temp';
    $server->setRelation('sshConfig', $sshConfig);

    // Mock the Process
    $mockProcess = Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(null);
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('stop')->andReturn(0);

    // Create partial mock - don't mock createTunnelProcess to test key file creation
    $service = Mockery::mock(SshTunnelService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('allocateLocalPort')->once()->andReturn(54322);
    $service->shouldReceive('createTunnelProcess')
        ->once()
        ->andReturnUsing(function ($command) use ($mockProcess) {
            // Verify the command contains the key file path
            expect($command)->toContain('-i');
            expect($command)->toContain('ssh_key_');

            return $mockProcess;
        });
    $service->shouldReceive('waitForTunnel')->once()->andReturn(true);

    // Act
    $result = $service->establish($server);

    // Assert
    expect($result['port'])->toBe(54322);

    // Cleanup - this should also remove the temp key file
    $service->close();

    // Verify key file was cleaned up
    $keyFiles = glob(sys_get_temp_dir().'/ssh_key_*');
    expect($keyFiles)->toBeEmpty();
});
