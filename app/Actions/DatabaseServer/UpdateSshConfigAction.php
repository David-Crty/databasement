<?php

namespace App\Actions\DatabaseServer;

use App\Models\DatabaseServerSshConfig;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateSshConfigAction
{
    use AsFake, AsObject;

    /**
     * Change an SSH configuration.
     *
     * A credential left blank keeps the stored one, since none is ever read
     * back to a client. A new private key without its own passphrase drops
     * the passphrase of the key it replaces, and switching the authentication
     * type drops the other type's credentials.
     *
     * @param  array<string, mixed>  $attributes  Validated columns to change; a missing key is left unchanged
     */
    public function handle(DatabaseServerSshConfig $sshConfig, array $attributes): DatabaseServerSshConfig
    {
        foreach (DatabaseServerSshConfig::SENSITIVE_FIELDS as $field) {
            if (array_key_exists($field, $attributes) && blank($attributes[$field])) {
                unset($attributes[$field]);
            }
        }

        if (filled($attributes['private_key'] ?? null) && ! array_key_exists('key_passphrase', $attributes)) {
            $attributes['key_passphrase'] = null;
        }

        $attributes['port'] ??= $sshConfig->port;

        $sshConfig->fill($attributes);
        $sshConfig->clearUnusedCredentials();
        $sshConfig->save();

        return $sshConfig;
    }
}
