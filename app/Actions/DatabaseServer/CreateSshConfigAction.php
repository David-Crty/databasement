<?php

namespace App\Actions\DatabaseServer;

use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateSshConfigAction
{
    use AsFake, AsObject;

    /**
     * Create an SSH configuration, keeping only the credentials of its
     * authentication type.
     *
     * @param  array<string, mixed>  $attributes  Validated host, username, auth_type, and optionally port (22), compression (off), password, private_key, key_passphrase
     */
    public function handle(Organization $organization, array $attributes): DatabaseServerSshConfig
    {
        $sshConfig = new DatabaseServerSshConfig([
            'port' => 22,
            'compression' => false,
            ...array_filter($attributes, filled(...)),
        ]);
        $sshConfig->organization_id = $organization->id;
        $sshConfig->clearUnusedCredentials();
        $sshConfig->save();

        return $sshConfig;
    }
}
