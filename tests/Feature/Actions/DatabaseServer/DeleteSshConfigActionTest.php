<?php

use App\Actions\DatabaseServer\DeleteSshConfigAction;
use App\Exceptions\DatabaseServer\SshConfigInUseException;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;

test('it deletes a configuration no server uses', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create();

    DeleteSshConfigAction::run($sshConfig);

    expect(DatabaseServerSshConfig::find($sshConfig->id))->toBeNull();
});

test('a configuration a server still connects through is kept', function () {
    $sshConfig = DatabaseServer::factory()->withSshTunnel()->create()->sshConfig;

    expect(fn () => DeleteSshConfigAction::run($sshConfig))->toThrow(SshConfigInUseException::class);

    expect(DatabaseServerSshConfig::find($sshConfig->id))->not->toBeNull();
});
