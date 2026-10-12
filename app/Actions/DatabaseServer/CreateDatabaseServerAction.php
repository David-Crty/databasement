<?php

namespace App\Actions\DatabaseServer;

use App\Actions\Backup\SyncBackupConfigurationsAction;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Support\DatabaseServerExtraConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateDatabaseServerAction
{
    use AsFake, AsObject;

    /**
     * Create a server with its SSH tunnel, backup configurations and
     * notification channels, all or nothing.
     *
     * @param  array<string, mixed>  $attributes  Validated server columns and extra-config fields (no backups, no inline SSH)
     * @param  array<int, array<string, mixed>>|null  $backups  Backup configurations; null creates none
     * @param  array<string, mixed>|null  $sshConfig  SSH settings to create, or to apply to the configuration whose `id` they carry, and link
     * @param  list<string>|null  $notificationChannelIds  Channels to notify; null links none
     *
     * @throws ValidationException When a backup configuration is rejected
     */
    public function handle(
        Organization $organization,
        array $attributes,
        ?array $backups = null,
        ?array $sshConfig = null,
        ?array $notificationChannelIds = null,
    ): DatabaseServer {
        return DB::transaction(function () use ($organization, $attributes, $backups, $sshConfig, $notificationChannelIds): DatabaseServer {
            if ($sshConfig !== null) {
                $attributes['ssh_config_id'] = $this->saveSshConfig($organization, $sshConfig);
            }

            $attributes['backups_enabled'] ??= true;
            DatabaseServerExtraConfig::fold($attributes);

            $server = new DatabaseServer($attributes);
            $server->organization_id = $organization->id;
            $server->save();

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
    private function saveSshConfig(Organization $organization, array $sshConfig): string
    {
        $existing = is_string($sshConfig['id'] ?? null) ? DatabaseServerSshConfig::find($sshConfig['id']) : null;
        unset($sshConfig['id']);

        return $existing !== null
            ? UpdateSshConfigAction::run($existing, $sshConfig)->id
            : CreateSshConfigAction::run($organization, $sshConfig)->id;
    }
}
