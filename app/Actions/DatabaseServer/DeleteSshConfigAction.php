<?php

namespace App\Actions\DatabaseServer;

use App\Exceptions\DatabaseServer\SshConfigInUseException;
use App\Models\DatabaseServerSshConfig;
use App\Models\Scopes\OrganizationScope;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteSshConfigAction
{
    use AsFake, AsObject;

    /**
     * @throws SshConfigInUseException While database servers still connect through it
     */
    public function handle(DatabaseServerSshConfig $sshConfig): void
    {
        if ($sshConfig->databaseServers()->withoutGlobalScope(OrganizationScope::class)->exists()) {
            throw new SshConfigInUseException($sshConfig);
        }

        $sshConfig->delete();
    }
}
