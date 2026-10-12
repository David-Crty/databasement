<?php

namespace App\Actions\DatabaseServer;

use App\Actions\Backup\SyncBackupConfigurationsAction;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Support\DatabaseServerExtraConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateDatabaseServerAction
{
    use AsFake, AsObject;

    /**
     * Change a server with its SSH tunnel, backup configurations and
     * notification channels, all or nothing. A blank password keeps the
     * stored one.
     *
     * @param  array<string, mixed>  $attributes  Validated server columns and extra-config fields to change (no backups, no inline SSH)
     * @param  array<int, array<string, mixed>>|null  $backups  Backup configurations to sync to; null leaves them untouched
     * @param  array<string, mixed>|null  $sshConfig  SSH settings to create, or to apply to the configuration whose `id` they carry, and link
     * @param  list<string>|null  $notificationChannelIds  Channels to notify; null leaves them untouched
     *
     * @throws ValidationException When a backup configuration is rejected
     */
    public function handle(
        DatabaseServer $server,
        array $attributes,
        ?array $backups = null,
        ?array $sshConfig = null,
        ?array $notificationChannelIds = null,
    ): DatabaseServer {
        if (array_key_exists('password', $attributes) && blank($attributes['password'])) {
            unset($attributes['password']);
        }

        return DB::transaction(function () use ($server, $attributes, $backups, $sshConfig, $notificationChannelIds): DatabaseServer {
            if ($sshConfig !== null) {
                $attributes['ssh_config_id'] = $this->saveSshConfig($server, $sshConfig);
            }

            DatabaseServerExtraConfig::fold($attributes, $server->extra_config, $server->database_type->value);

            $server->update($attributes);

            if ($backups !== null) {
                SyncBackupConfigurationsAction::run($server, $backups);
            }

            if ($notificationChannelIds !== null) {
                $server->notificationChannels()->sync($notificationChannelIds);
            }

            return $server;
        });
    }

    /**
     * @param  array<string, mixed>  $sshConfig
     */
    private function saveSshConfig(DatabaseServer $server, array $sshConfig): string
    {
        $existing = is_string($sshConfig['id'] ?? null) ? DatabaseServerSshConfig::find($sshConfig['id']) : null;
        unset($sshConfig['id']);

        return $existing !== null
            ? UpdateSshConfigAction::run($existing, $sshConfig)->id
            : CreateSshConfigAction::run($server->organization, $sshConfig)->id;
    }
}
